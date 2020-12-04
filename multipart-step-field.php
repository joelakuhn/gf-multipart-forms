<?php

namespace GravityFormsMultipartForms;

use GF_Field_MultiSelect;

class GF_Field_Multipart_Step extends \GF_Field {
    public $type = 'multipart_step';

    public $inputName = 'step';
    public $allowsPrepopulate = true;
    public $enableChoiceValue = true;
    public $defaultValue = '{first_step}';
    public $visibility = 'hidden';
    public $adminOnly = true;

    private static $has_registered_single_events = false;

    public function __construct($data = []) {
        parent::__construct($data);
        GF_Field_Multipart_Step::register_single_events();
    }

    private static function register_single_events() {
        if (!GF_Field_Multipart_Step::$has_registered_single_events) {
            add_action('gform_editor_js_set_default_values', 'GravityFormsMultipartForms\\GF_Field_Multipart_Step::set_default_values_js');
        }
        GF_Field_Multipart_Step::$has_registered_single_events = true;
    }

    public function get_form_editor_field_title() {
        return 'Multipart Step';
    }

    public function get_form_editor_button() {
        return [
            'group' => 'advanced_fields',
            'text'  => $this->get_form_editor_field_title(),
        ];
    }

    function get_form_editor_field_settings() {
        new \GF_Field_Select();
        return [
            'conditional_logic_field_setting',
            'error_message_setting',
            'label_setting',
            'admin_label_setting',
            'choices_setting',
            'default_value_setting',
            'css_class_setting',
        ];
    }

    public function is_conditional_logic_supported() {
        return true;
    }

    // Builds the actual form inputs for the field
    public function get_field_input($form, $value = '', $entry = null) {
        $form_id         = $form['id'];
        $is_entry_detail = $this->is_entry_detail();
        $id              = (int) $this->id;
        $is_form_editor  = $this->is_form_editor();

        if (is_array($value)) {
            $steps = $value[$id] ?? '';
            $completed_steps = $value[$id . '.2'] ?? '';
            if (empty($steps)) {
                if ($this->defaultValue == "{first_step}" && isset($this->choices[0])) {
                    $steps = $this->choices[0]['value'];
                }
                else {
                    $steps = $this->defaultValue;
                }
            }
        }
        else {
            $steps = $value;
        }

        if ( $is_entry_detail ) {
            $input = "<input type='hidden' id='input_{$id}' name='input_{$id}' value='{$value}' />";
            
            return "{$input}<br>Multipart Step fields are not editable.";
        }

        ob_start();

        ?>
        <div class="ginput_container" id="gf_login_container<?= $form_id ?>">
            <input type="hidden" id="input_<?= $form_id ?>_<?= $id ?>" value="<?= $steps ?>" name="input_<?= $id ?>" />
            <input type="hidden" id="input_<?= $form_id ?>_<?= $id ?>_2" value="<?= $completed_steps ?>" name="input_<?= $id ?>.2" />
            <?php if (!$is_form_editor): ?>
                <p><?= $this->get_choice_label($value) ?></p>
            <?php endif; ?>
        </div>

        <?php

        return ob_get_clean();
    }

    // Helper function to fetch the choice label given the choice value
    private function get_choice_label($value) {
        foreach ($this->choices ?? [] as $choice) {
            if ($choice['value'] === $value) {
                return $choice['text'];
            }
        }
        return null;
    }

    // Create the "Entry" view value for the field
    public function get_value_entry_detail( $value, $currency = '', $use_text = false, $format = 'html', $media = 'screen' ) {
        if ( is_array( $value ) ) {
            $completed_steps = explode(',', $value[$this->id . '.2'] ?? '');

            if (count($completed_steps) === count($this->choices)) {
                $return = 'All steps completed';
            }
            else if (!empty($completed_steps)) {
                $completed_step_labels = [];
                foreach ($completed_steps as $completed_step) {
                    $completed_step_labels[] = $this->get_choice_label($completed_step);
                }
                $return = implode(', ', $completed_step_labels);
            }
            
        } else {
            $return = '';
        }

        if ($format === 'html') {
            $return = esc_html($return);
        }

        return $return;
    }

    // Do not overwrite previously submitted values
    public function get_value_save_entry( $value, $form, $input_name, $lead_id, $lead ) {
        if ($input_name === "input_{$this['id']}_2") {
            $original_entry = \GFAPI::get_entry($lead['id']);
            $original_value = is_array($original_entry) ? ($original_entry[$this->id . '.2'] ?? '') : '';
            if (empty($original_value)) {
                $value = $_POST["input_{$this['id']}"];
            }
            else {
                $value = $original_value . ',' . $_POST["input_{$this['id']}"];
            }
            return $this->sanitize_entry_value($value, $form['id']);
        }
        return parent::get_value_save_entry($value, $form, $input_name, $lead_id, $lead);
    }

    // Only allow one steps field on the form. 
    public function get_form_editor_inline_script_on_page_render() {
        $field_title = $this->get_form_editor_field_title();
        $script = <<<FIELD_COUNT_LIMIT_JS
            gform.addFilter('gform_form_editor_can_field_be_added', function(is_valid, type) {
                if (type == 'multipart_step') {
                    if (GetFieldsByType(['multipart_step']).length > 0) {
                        alert('Only one Multipart field can be added to the form');
                        return false;
                    }
                }
                return is_valid;
            })
            FIELD_COUNT_LIMIT_JS;

        return $script;
    }

    // Configure the initial values for the step field when it is added to the form.
    // The input name sets the GET parameter for prepopulation.
    public static function set_default_values_js() {
        ?>
        case "multipart_step":
            field.label = 'Steps';
            field.visibility = 'hidden';
            if (!field.choices) {
                field.choices = [
                    new Choice('Step One', 'one'),
                    new Choice('Step Two', 'two')
                ];
            }
            var input1 = new Input(field.id, 'Current Step');
            var input2 = new Input(field.id + '.2', 'Completed Steps');
            input1.name = 'step';
            field.inputs = [ input1, input2 ];
            break;
        <?php
    }
}


\GF_Fields::register(new GF_Field_Multipart_Step());
