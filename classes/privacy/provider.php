<?php

namespace tool_externaltaskmonitor\privacy;

use core_privacy\local\metadata\null_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy Subsystem for tool_externaltaskmonitor implementing null_provider.
 */
class provider implements null_provider {

    /**
     * @inheritdoc
     */
    public static function get_reason() : string {
        return 'privacy:metadata';
    }
}
