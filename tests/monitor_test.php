<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Test coverage for external task monitor.
 *
 * @package    tool_externaltaskmonitor
 * @copyright  The Regents of the University of California
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_externaltaskmonitor;

use auth_db\task\sync_users;
use context_system;
use core\task\manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use externallib_advanced_testcase;
use required_capability_exception;
use tool_externaltaskmonitor\external\monitor;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Test coverage for external task monitor.
 *
 * @covers \tool_externaltaskmonitor\external\monitor
 */
final class monitor_test extends externallib_advanced_testcase {

    /**
     * {@inheritDoc}
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Tests web service function input parameters definition.
     */
    public function test_get_scheduled_tasks_parameters(): void {
        $parameters = monitor::get_scheduled_tasks_parameters();
        $this->assertTrue($parameters instanceof external_function_parameters);
        $this->assertEmpty($parameters->keys);
    }

    /**
     * Tests web service function return value definition.
     */
    public function test_get_scheduled_tasks_returns(): void {
        $structure = monitor::get_scheduled_tasks_returns();
        $this->assertTrue($structure instanceof external_multiple_structure);

        $innerstructure = $structure->content;
        $this->assertTrue($innerstructure instanceof external_single_structure);
        $this->assertCount(4, $innerstructure->keys);

        $componentvalue = $innerstructure->keys['component'];
        $this->assertTrue( $componentvalue instanceof external_value);
        $this->assertEquals(VALUE_REQUIRED, $componentvalue->required);
        $this->assertEquals('Task Component', $componentvalue->desc);
        $this->assertEquals(PARAM_TEXT, $componentvalue->type);

        $componentvalue = $innerstructure->keys['class'];
        $this->assertTrue( $componentvalue instanceof external_value);
        $this->assertEquals(VALUE_REQUIRED, $componentvalue->required);
        $this->assertEquals('Task Class', $componentvalue->desc);
        $this->assertEquals(PARAM_TEXT, $componentvalue->type);

        $componentvalue = $innerstructure->keys['lastruntime'];
        $this->assertTrue( $componentvalue instanceof external_value);
        $this->assertEquals(VALUE_REQUIRED, $componentvalue->required);
        $this->assertEquals('Last Run Time', $componentvalue->desc);
        $this->assertEquals(PARAM_INT, $componentvalue->type);

        $componentvalue = $innerstructure->keys['disabled'];
        $this->assertTrue( $componentvalue instanceof external_value);
        $this->assertEquals(VALUE_REQUIRED, $componentvalue->required);
        $this->assertEquals('Disabled', $componentvalue->desc);
        $this->assertEquals(PARAM_BOOL, $componentvalue->type);
    }

    /**
     * Tests output of web service function call.
     */
    public function test_get_scheduled_tasks(): void {
        global $DB;

        // Set up a logged-in user with the necessary permissions.
        $user = $this->getDataGenerator()->create_user();
        $role = $DB->get_record('role', ['shortname' => 'user']);
        $context = context_system::instance();
        $this->getDataGenerator()->role_assign($role->id, $user->id, $context->id);
        $this->assignUserCapability('moodle/site:config', $context->id, $role->id);
        $this->setUser($user);

        // Since the entire output is a bit of a moving target, let's use one core
        // task as example under the assumption that it will always be there.
        // If this task ever goes away then replace it with another one that's present [ST 2024/11/25].
        $classname = sync_users::class;

        // Load the task and verify initial state.
        // We're using low-level function calls from the DB subsystem, since
        // loading scheduled tasks via the \core\task\manager component applies
        // config overrides that clobber the raw data points as they exist in the database,
        // and I haven't figured out how to work around this, yet [ST 2024/11/25].
        $taskrecord = $DB->get_record(
            'task_scheduled',
            ['classname' => manager::get_canonical_class_name($classname)],
            strictness: MUST_EXIST
        );
        $this->assertEquals(0, $taskrecord->lastruntime);
        $this->assertEquals('1', $taskrecord->disabled);

        // Re-retrieve task info and check values in output.
        $tasksinfo = external_api::clean_returnvalue(
            monitor::get_scheduled_tasks_returns(),
            monitor::get_scheduled_tasks()
        );
        $taskinfo = $this->find_scheduled_task_in_output($tasksinfo, $classname);
        $this->assertEquals($taskrecord->component, $taskinfo['component']);
        $this->assertEquals($classname, $taskinfo['class']);
        $this->assertEquals(0, $taskinfo['lastruntime']);
        $this->assertTrue($taskinfo['disabled']);

        $now = time();
        // Update the task with a new last-run-time timestamp and enable it.
        $taskrecord = $DB->get_record(
            'task_scheduled',
            ['classname' => manager::get_canonical_class_name($classname)],
            strictness: MUST_EXIST
        );
        $taskrecord->lastruntime = $now;
        $taskrecord->disabled = '0';
        $DB->update_record('task_scheduled', $taskrecord);

        // Re-retrieve task info and check for updated values in output.
        $tasksinfo = external_api::clean_returnvalue(
            monitor::get_scheduled_tasks_returns(),
            monitor::get_scheduled_tasks()
        );
        $taskinfo = $this->find_scheduled_task_in_output($tasksinfo, $classname);
        $this->assertEquals($now, $taskinfo['lastruntime']);
        $this->assertFalse($taskinfo['disabled']);
    }

    /**
     * Tests web service function call with insufficient permissions.
     */
    public function test_get_scheduled_tasks_fails_on_insufficient_permissions(): void {
        global $DB;

        // Set up a logged-in user without the necessary permissions.
        $user = $this->getDataGenerator()->create_user();
        $role = $DB->get_record('role', ['shortname' => 'user']);
        $context = context_system::instance();
        $this->getDataGenerator()->role_assign($role->id, $user->id, $context->id);
        $this->unassignUserCapability('moodle/site:config', $context->id, $role->id);
        $this->setUser($user);

        $this->expectException(required_capability_exception::class);
        monitor::get_scheduled_tasks();
    }

    /**
     * Extracts and returns the task info from the given lists by its sync task classname.
     *
     * @param array $tasks The list of scheduled tasks to search.
     * @param string $classname The class name of the scheduled task.
     * @return array|null The scheduled task info for the given component and class, NULL if none was found.
     */
    protected function find_scheduled_task_in_output(array $tasks, string $classname): ?array {
        $filteredtasks = array_values(
            array_filter(
                $tasks,
                function($task) use ($classname) {
                    return $task['class'] === $classname;
                }
            )
        );
        return empty($filteredtasks) ? null : $filteredtasks[0];
    }
}
