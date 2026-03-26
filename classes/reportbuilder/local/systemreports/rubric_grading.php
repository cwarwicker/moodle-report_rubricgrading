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

declare(strict_types=1);

namespace report_rubricgrading\reportbuilder\local\systemreports;

use core_reportbuilder\system_report;
use core_reportbuilder\local\helpers\database;
use report_rubricgrading\reportbuilder\local\entities\grader;
use report_rubricgrading\reportbuilder\local\entities\rubric_assignment;
use report_rubricgrading\reportbuilder\local\entities\rubric_criterion;
use report_rubricgrading\reportbuilder\local\entities\student;

/**
 * Rubric grading system report.
 *
 * Displays per-criterion rubric grading data for all students who have graded
 * submissions on a specific assignment (identified by cmid).
 *
 * Each row represents one rubric criterion filling for one student:
 *   student × criterion → score, level definition, remark, overall grade.
 *
 * @package    report_rubricgrading
 * @copyright  2025 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rubric_grading extends system_report {

    /**
     * Initialise the report: set main table, join entities, and configure columns/filters.
     */
    protected function initialise(): void {
        $cmid = $this->get_parameter('cmid', 0, PARAM_INT);

        // ------------------------------------------------------------------ //
        // 1. Rubric criterion entity – owns the main table.                   //
        // ------------------------------------------------------------------ //
        $criterionentity = new rubric_criterion();
        $grf      = $criterionentity->get_table_alias('gradingform_rubric_fillings');
        $criteria = $criterionentity->get_table_alias('gradingform_rubric_criteria');
        $level    = $criterionentity->get_table_alias('gradingform_rubric_levels');

        $this->set_main_table('gradingform_rubric_fillings', $grf);
        $this->add_entity($criterionentity
            ->add_join("JOIN {gradingform_rubric_criteria} {$criteria}
                           ON {$criteria}.id = {$grf}.criterionid")
            ->add_join("JOIN {gradingform_rubric_levels} {$level}
                           ON {$level}.id = {$grf}.levelid")
        );

        // ------------------------------------------------------------------ //
        // 2. Assignment grading entity – grade record, assignment metadata.   //
        // ------------------------------------------------------------------ //
        $assignmententity = new rubric_assignment();
        $gin = $assignmententity->get_table_alias('grading_instances');
        $ag  = $assignmententity->get_table_alias('assign_grades');
        $gd  = $assignmententity->get_table_alias('grading_definitions');
        $ga  = $assignmententity->get_table_alias('grading_areas');
        $ctx = $assignmententity->get_table_alias('context');
        $cm  = $assignmententity->get_table_alias('course_modules');
        $asg = $assignmententity->get_table_alias('assign');
        $afc = $assignmententity->get_table_alias('assignfeedback_comments');

        $this->add_entity($assignmententity
            ->add_join("JOIN {grading_instances} {$gin}
                           ON {$gin}.id = {$grf}.instanceid")
            ->add_join("JOIN {assign_grades} {$ag}
                           ON {$ag}.id = {$gin}.itemid")
            ->add_join("JOIN {grading_definitions} {$gd}
                           ON {$gd}.id = {$gin}.definitionid")
            ->add_join("JOIN {grading_areas} {$ga}
                           ON {$ga}.id = {$gd}.areaid")
            ->add_join("JOIN {context} {$ctx}
                           ON {$ctx}.id = {$ga}.contextid")
            ->add_join("JOIN {course_modules} {$cm}
                           ON {$cm}.id = {$ctx}.instanceid")
            ->add_join("JOIN {assign} {$asg}
                           ON {$asg}.id = {$cm}.instance")
            ->add_join("LEFT JOIN {assignfeedback_comments} {$afc}
                           ON {$afc}.grade = {$ag}.id")
        );

        // ------------------------------------------------------------------ //
        // 3. Student entity.                                                  //
        // ------------------------------------------------------------------ //
        $studententity = new student();
        $stu = $studententity->get_table_alias('user');

        $this->add_entity($studententity
            ->add_joins($assignmententity->get_joins())
            ->add_join("JOIN {user} {$stu}
                           ON {$stu}.id = {$ag}.userid AND {$stu}.deleted = 0")
        );

        // ------------------------------------------------------------------ //
        // 4. Grader entity (second user join, distinct alias).               //
        // ------------------------------------------------------------------ //
        $graderentity = new grader();
        $rubm = $graderentity->get_table_alias('user');

        $this->add_entity($graderentity
            ->add_joins($assignmententity->get_joins())
            ->add_join("JOIN {user} {$rubm}
                           ON {$rubm}.id = {$ag}.grader")
        );

        // ------------------------------------------------------------------ //
        // 5. Base conditions: active grading instances, specific assignment.  //
        // ------------------------------------------------------------------ //
        $paramstatus = database::generate_param_name();
        $paramcmid   = database::generate_param_name();

        $this->add_base_condition_sql(
            "{$gin}.status = :{$paramstatus} AND {$cm}.id = :{$paramcmid}",
            [$paramstatus => 1, $paramcmid => $cmid]
        );

        // ------------------------------------------------------------------ //
        // 6. Columns to display.                                              //
        // ------------------------------------------------------------------ //
        $this->add_columns_from_entities([
            'student:fullname',
            'rubric_criterion:description',
            'rubric_criterion:score',
            'rubric_criterion:leveldefinition',
            'rubric_criterion:remark',
            'rubric_assignment:grade',
            'rubric_assignment:overallfeedback',
            'grader:fullname',
            'rubric_assignment:timegraded',
        ]);

        // Rename generic grader fullname column for clarity.
        if ($column = $this->get_column('grader:fullname')) {
            $column->set_title(new \lang_string('gradedby', 'report_rubricgrading'));
        }

        // ------------------------------------------------------------------ //
        // 7. Filters.                                                         //
        // ------------------------------------------------------------------ //
        $this->add_filters_from_entities([
            // Student identity.
            'student:firstname',
            'student:lastname',
            'student:email',
            'student:idnumber',
            'student:username',
            // Criterion detail.
            'rubric_criterion:description',
            'rubric_criterion:score',
            'rubric_criterion:remark',
            // Grade and timing.
            'rubric_assignment:grade',
            'rubric_assignment:timegraded',
            // Grader identity.
            'grader:firstname',
            'grader:lastname',
            'grader:email',
        ]);

        $this->set_initial_sort_column('student:fullname', SORT_ASC);
        $this->set_downloadable(true, get_string('pluginname', 'report_rubricgrading'));
    }

    /**
     * Confirm the current user has permission to view this report.
     *
     * @return bool
     */
    protected function can_view(): bool {
        $cmid = $this->get_parameter('cmid', 0, PARAM_INT);
        if (!$cmid) {
            return false;
        }
        return has_capability('report/rubricgrading:view', $this->get_context());
    }
}
