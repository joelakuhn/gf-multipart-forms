<?php
/*
Plugin Name: Gravity Forms Multipart Forms
Description: Adds the ability to submit forms in multiple steps.
Version: 1.0.0
Author: Joel Kuhn
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

namespace GravityFormsMultipartForms;

if (class_exists('\\GFCommon')):

require 'multipart-step-field.php';

class GF_Multipart_Forms {

    private $entry_id = false;
    private $step = false;
    private $access_key = false;
    private $is_non_default_step = false;
    private $all_steps_selected_steps = null;

    function __construct() {
        $this->entry_id = $_GET['entry_id'] ?? false;
        $this->step = $_GET['step'] ?? false;
        $this->access_key = $_GET['access_key'] ?? false;

        $this->is_non_default_step =
            $this->entry_id !== false &&
            $this->step !== false &&
            $this->access_key !== false;

        add_filter('gform_entry_id_pre_save_lead', [ $this, 'update_entry_id_before_save' ], 10, 2);
        add_filter('gform_entry_post_save', [ $this, 'load_full_entry_after_save' ], 10, 2);

        add_action('gform_pre_render', [ $this, 'prepopulate_previous_values' ], 10, 1);
        add_action('gform_pre_render', [ $this, 'apply_html_field_merge_tags' ], 10, 1);
        add_action('gform_pre_render', [ $this, 'enable_merge_tag_dynamic_values' ], 10, 1);
        add_action('gform_pre_render', [ $this, 'block_invalid_key' ], 10, 1);

        add_filter('gform_validation', [ $this, 'block_double_submission' ], 10, 1);

        add_filter('gform_admin_pre_render', [ $this, 'add_merge_tags_to_menu_js' ], 10, 1);

        add_filter('gform_replace_merge_tags', [ $this, 'next_step_merge_tag' ], 10, 6);
        add_filter('gform_replace_merge_tags', [ $this, 'all_fields_all_steps_merge_tag' ], 10, 6);
    }

    function generate_key($form_id, $entry_id, $step) {
        $data = "{$form_id}:{$entry_id}:{$step}";
        $hash = hash_hmac('sha256', $data, 'multipart_step:' . wp_salt());
        return $hash;
    }

    function validate_key($form_id, $entry_id, $step, $access_key) {
        $new_key = $this->generate_key($form_id, $entry_id, $step);
        return $access_key === $new_key;
    }

    function is_valid($form_id) {
        if (isset($form_id['id'])) $form_id = $form_id['id'];
        return $this->validate_key($form_id, $this->entry_id, $this->step, $this->access_key);
    }


    // Gives Gravity Forms the previously submitted entry_id before saving
    // to the database so Gravity Forms will update the entry rather than
    // creating a new one.
    function update_entry_id_before_save($entry_id, $form) {
        if ($this->is_valid($form)) {
            return $this->entry_id;
        }

        return $entry_id;
    }

    // Loads the fully updated entry after it is saved so that submissions
    // and confirmations have access to the full entry history.
    function load_full_entry_after_save($entry, $form) {
        if ($this->is_valid($form)) {
            $updated_entry = \GFAPI::get_entry($this->entry_id);
            if ($updated_entry) {
                return $updated_entry;
            }
        }

        return $entry;
    }


    // Prepopulate fields in previous steps' fields with previously submitted values
    // so that conditional logic can cross submission boundaries.
    function prepopulate_previous_values($form) {
        if ($this->is_valid($form)) {
            $entry = \GFAPI::get_entry($this->entry_id);
            if ($entry) {
                foreach ($form['fields'] ?? [] as &$field) {
                    if (\GFFormsModel::is_field_hidden($form, $field, [], null)) {
                        if (isset($entry[$field->id])) {
                            $field['defaultValue'] = $entry[$field->id];
                        }
                        else if (isset($field->fields)) {
                            foreach ($field->inputs as &$subfield) {
                                if (isset($entry[$subfield['id']])) {
                                    $subfield['defaultValue'] = $entry[$subfield['id']];
                                }
                            }
                        }
                    }
                }
            }
        }
        return $form;
    }


    // Allow html fields to use merge tags from previously submitted values.
    function apply_html_field_merge_tags($form) {
        if ($this->is_valid($form)) {
            foreach ($form['fields'] as &$field) {
                if (!isset($entry)) $entry = \GFAPI::get_entry($this->entry_id);

                $field->content = \GFCommon::replace_variables(
                    $field->content, // text
                    $form, // $form
                    $entry, // $entry
                    false, // $url_encode
                    true, // $esc_html
                    false // $nl2br
                );
            }
        }
        return $form;
    }


    // Allow fields on a multipart form to be prepopulated with merge
    // tags referencing previously submitted values.
    function enable_merge_tag_dynamic_values($form) {
        if ($this->is_valid($form)) {
            $entry_id = $this->entry_id;
            add_filter('gform_field_value', function($value, $field, $name) use ($form, $entry_id) {

                if (strpos($name, '{') !== false) {
                    $entry = \GFAPI::get_entry($entry_id);
                    $new_value = \GFCommon::replace_variables(
                        $name, // text
                        $form, // $form
                        $entry, // $entry
                        false, // $url_encode
                        true, // $esc_html
                        false // $nl2br
                    );
                    if ($new_value !== $name) {
                        $value = $new_value;
                    }
                }

                return $value;
            }, 10, 3);
        }

        return $form;
    }


    // Checks that the provided key is valid for the current step
    function block_invalid_key($form) {
        if ($this->is_non_default_step && !$this->is_valid($form)) {
            add_filter('gform_form_not_found_message', function($message) {
                return '<div class="alert alert-danger">You are not authorized to complete this section of the form.</div>';
            });
            return null;
        }
        return $form;
    }


    // Checks if the step being submitted has been submitted before.
    function block_double_submission($validation_result) {
        $form = $validation_result['form'];
        if ($this->is_non_default_step && $this->is_valid($form)) {
            $step_field = \GFFormsModel::get_fields_by_type($form, 'multipart_step')[0] ?? false;
            if ($step_field) {
                $entry = \GFAPI::get_entry($this->entry_id);
                $completed = explode(',', $entry[$step_field->id . '.2'] ?? '');
                if (in_array($this->step, $completed)) {
                    $validation_result['form'] = null;
                    $validation_result['is_valid'] = false;
                    add_filter('gform_form_not_found_message', function($message) {
                        return '<div class="alert alert-danger">This step has already be completed for this form.</div>';
                    });
                }
            }

        }

        return $validation_result;
    }


    // Adds the multipart merge_tags to the dropdowns in the form editor.
    function add_merge_tags_to_menu_js($form) {
        $step_fields = \GFFormsModel::get_fields_by_type($form, 'multipart_step');
        if (count($step_fields) > 0):
        ?>
        <script>
            gform.addFilter('gform_merge_tags', function(mergeTags, elementId, hideAllFields, excludeFieldTypes, isPrepop, option){
                mergeTags['multipart'] = {
                    label: 'Multipart',
                    tags: [
                        { label: 'All Fields for All Steps', tag: '{all_fields_all_steps}' },
                        <?php foreach ($step_fields as $field): ?>
                            <?php foreach ($field->choices as $choice): ?>
                                { label: 'URL to: <?= $choice['text'] ?>', tag: '{next_step:<?= $choice['value'] ?>}' },
                                { label: 'Link to: <?= $choice['text'] ?>', tag: '{next_step:<?= $choice['value'] ?>:Complete Next Step}' },
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    ]
                };
                return mergeTags;
            });
        </script>
        <?php
        endif;
        return $form;
    }

    // {next_step:stepname:<link body>} creates a link to the current form with the
    // parameters needed to complete the indicated step.
    function next_step_merge_tag($text, $form, $entry, $url_encode, $esc_htm, $nl2br) {

        $custom_merge_tag = '/\\{next_step:([^:\\}]+):?([^:\\}]+)?\\}/';

        if (!preg_match_all($custom_merge_tag, $text, $matches)) return $text;

        $base_url = $entry['source_url'];
        $base_url = preg_replace('/[\\?&]step=[^&]*/', '', $base_url);
        $base_url = preg_replace('/[\\?&]access_key=[^&]*/', '', $base_url);
        $base_url = preg_replace('/[\\?&]entry_id=[^&]*/', '', $base_url);
        $base_url = strpos($base_url, '?') === false ? $base_url . '?' : $base_url . '&';

        for ($i=0; $i<count($matches[1]); $i++) {
            $step = $matches[1][$i] ?? false;
            $link_title = $matches[2][$i] ?? false;

            $access_key = $this->generate_key($form['id'], $entry['id'], $step);
            $step_text = "{$base_url}entry_id={$entry['id']}&step={$step}&access_key={$access_key}";
            if ($link_title) {
                $step_text = "<a target='_blank' href='{$step_text}'>{$link_title}</a>";
            }
            $text = str_replace($matches[0][$i], $step_text, $text);
        }

        return $text;
    }

    // The {all_fields_all_steps} merge tag calls {all_fields}, but shows
    // all previously submitted steps, even though they are hidden for
    // this submission. You can also set a specific group of steps to
    // display using the `steps=step1,step2,...` filter.
    function all_fields_all_steps_merge_tag($text, $form, $entry, $url_encode, $esc_html, $nl2br) {
        $merge_tag = '/\\{all_fields_all_steps(:?[^\\}]*)\\}/';
        if (!preg_match_all($merge_tag, $text, $matches)) return $text;

        \GFCache::flush();
        add_filter('gform_is_value_match', [ $this, 'ignore_step_logic' ], 10, 6);

        for ($i=0; $i<count($matches[1]); $i++) {
            $formatters = $matches[1][$i] ?: '';

            preg_match('/^:steps=([^:]+)/', $formatters, $steps_match);
            if ($steps_match) {
                $this->all_steps_selected_steps = $steps_match ? explode(',', $steps_match[1]) : null;
                $formatters = substr($formatters, strlen($steps_match[0]));
            }

            $all_fields = \GFCommon::replace_variables(
                "{all_fields{$formatters}}", // text
                $form, // $form
                $entry, // $entry
                $url_encode, // $url_encode
                $esc_html, // $esc_html
                $nl2br // $nl2br
            );
            $text = str_replace($matches[0][$i], $all_fields, $text);

            $this->all_steps_selected_steps = null;
        }

        remove_filter('gform_is_value_match', [ $this, 'ignore_step_logic' ]);
        \GFCache::flush();

        return $text;
    }

    function ignore_step_logic($is_match, $field_value, $target_value, $operation, $source_field, $rule) {
        if ($source_field['type'] === 'multipart_step') {
            if ($this->all_steps_selected_steps) {
                return in_array($target_value, $this->all_steps_selected_steps);
            }
            return true;
        }
        return $is_match;
    }
}

new GF_Multipart_Forms();

endif;

