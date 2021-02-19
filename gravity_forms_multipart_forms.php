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

add_action('plugins_loaded', function() {

    if (class_exists('\\GFCommon')) {
        require 'multipart-step-field.php';
        require 'multipart-forms-class.php';
    }

});

