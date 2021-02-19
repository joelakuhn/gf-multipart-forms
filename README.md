# Multipart Forms for Gravity Forms

This plugin adds a single field, the Multipart Step field. This allows you to specify any number of distinct steps in a forms workflow. The field keeps track of which step the user is on. You can then simply show or hide sections, pages, form fields or whatever based on conditional logic.

You can also send notifications with links to any step—or multiple steps—in the workflow based on the current step or any number of completed steps, allowing for linear or branching workflows.

Links to specific steps include a SHA256 hash which must be validated in order to render the form. Additionally, any field that was previously submitted will not be overwritten, so manual alterations of fields from other steps should not be a concern.

