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
use core_reportbuilder\local\helpers\format;
use core_reportbuilder\local\report\column;
use core_reportbuilder\local\report\filter;
use core_reportbuilder\local\filters\date;
use core_reportbuilder\local\filters\number;
use core_reportbuilder\local\filters\text;
use lang_string;
use moodle_database;
use xmldb_table;

/**
 * Rubric grading system report – pivoted view.
 *
 * Each row represents one student; rubric criteria appear as columns rather
 * than as separate rows.  A temporary table is built per-request using
 * Moodle's standard XMLDB temp-table API, populated with pivoted data via a
 * PHP-level pivot, then set as the Report Builder main table.  Dynamic
 * columns are registered for every criterion found in the rubric.
 *
 * @package    report_rubricgrading
 * @copyright  2025 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rubric_grading extends system_report {
    /** Entity/namespace label used for all columns and filters. */
    private const ENTITY = 'rubricpivot';

    /** Temp table name (without DB prefix). */
    private const TEMP_TABLE = 'tmp_rubricpivot';

    /**
     * Initialise the report: build the pivot temp table and define columns/filters.
     */
    protected function initialise(): void {
        global $DB;

        $cmid     = $this->get_parameter('cmid', 0, PARAM_INT);
        $criteria = $this->get_rubric_criteria($DB, $cmid);

        $this->build_pivot_table($DB, $cmid, $criteria);

        $alias = 'rp';
        $this->set_main_table(self::TEMP_TABLE, $alias);

        $this->annotate_entity(self::ENTITY, new lang_string('pluginname', 'report_rubricgrading'));

        $this->define_columns($alias, $criteria);
        $this->define_filters($alias, $criteria);

        $this->set_initial_sort_column(self::ENTITY . ':student_fullname', SORT_ASC);
        $this->set_downloadable(true, get_string('pluginname', 'report_rubricgrading'));
    }

    /**
     * Return the rubric criteria for the given assignment course module,
     * ordered by sortorder.
     *
     * @param moodle_database $DB
     * @param int             $cmid
     * @return array  Indexed array of stdClass objects (id, description, sortorder).
     */
    private function get_rubric_criteria(moodle_database $DB, int $cmid): array {
        if ($cmid <= 0) {
            return [];
        }
        $sql = "SELECT grc.id, grc.description, grc.sortorder
                  FROM {gradingform_rubric_criteria} grc
                  JOIN {grading_definitions} gd  ON gd.id  = grc.definitionid
                  JOIN {grading_areas}       ga  ON ga.id  = gd.areaid
                  JOIN {context}             ctx ON ctx.id = ga.contextid
                  JOIN {course_modules}      cm  ON cm.id  = ctx.instanceid
                 WHERE cm.id = :cmid
              ORDER BY grc.sortorder";
        return array_values($DB->get_records_sql($sql, ['cmid' => $cmid]));
    }

    /**
     * Create the pivot temporary table and populate it with one row per student.
     *
     * The schema is dynamic: static student/assignment fields are always present,
     * and three fields per criterion (score, level definition, remark) are added
     * based on the rubric criteria found for the assignment.
     *
     * @param moodle_database $DB
     * @param int             $cmid
     * @param array           $criteria  Ordered criteria objects.
     */
    private function build_pivot_table(moodle_database $DB, int $cmid, array $criteria): void {
        $dbman = $DB->get_manager();

        // Drop any table left from a previous request in the same DB session
        // (table_exists may not detect MySQL temp tables, but that is fine
        // because MySQL temp tables are connection-scoped and do not survive
        // across HTTP requests).
        $xmldbtable = new xmldb_table(self::TEMP_TABLE);
        if ($dbman->table_exists($xmldbtable)) {
            $dbman->drop_table($xmldbtable);
        }

        // Static fields.
        $xmldbtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $xmldbtable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
        $xmldbtable->add_field('student_firstname', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $xmldbtable->add_field('student_lastname', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $xmldbtable->add_field('student_fullname', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $xmldbtable->add_field('student_email', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $xmldbtable->add_field('student_username', XMLDB_TYPE_CHAR, '100', null, null, null, '');
        $xmldbtable->add_field('student_idnumber', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $xmldbtable->add_field('grade', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $xmldbtable->add_field('gradeoutof', XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
        $xmldbtable->add_field('overallfeedback', XMLDB_TYPE_TEXT, null, null, null, null);
        $xmldbtable->add_field('grader_fullname', XMLDB_TYPE_CHAR, '255', null, null, null, '');
        $xmldbtable->add_field('timegraded', XMLDB_TYPE_INTEGER, '10', null, null, null, null);

        // Dynamic criterion fields (three per criterion).
        foreach ($criteria as $i => $criterion) {
            $n = $i + 1;
            $xmldbtable->add_field("crit{$n}_score", XMLDB_TYPE_NUMBER, '10, 5', null, null, null, null);
            $xmldbtable->add_field("crit{$n}_leveldef", XMLDB_TYPE_TEXT, null, null, null, null);
            $xmldbtable->add_field("crit{$n}_remark", XMLDB_TYPE_TEXT, null, null, null, null);
        }

        $xmldbtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $dbman->create_temp_table($xmldbtable);

        if ($cmid <= 0 || empty($criteria)) {
            return; // Nothing to populate; the empty table satisfies the schema.
        }

        // Fetch raw (un-pivoted) grading data.
        $rawsql = "SELECT grf.id,
                          ag.userid,
                          ag.grade,
                          asg.grade              AS gradeoutof,
                          afc.commenttext        AS overallfeedback,
                          ag.timemodified        AS timegraded,
                          stu.firstname,
                          stu.lastname,
                          stu.email,
                          stu.username,
                          stu.idnumber,
                          stu.firstnamephonetic,
                          stu.lastnamephonetic,
                          stu.middlename,
                          stu.alternatename,
                          grdr.firstname         AS grader_firstname,
                          grdr.lastname          AS grader_lastname,
                          grdr.firstnamephonetic AS grader_firstnamephonetic,
                          grdr.lastnamephonetic  AS grader_lastnamephonetic,
                          grdr.middlename        AS grader_middlename,
                          grdr.alternatename     AS grader_alternatename,
                          grc.id                 AS criterionid,
                          gl.score,
                          gl.definition          AS leveldef,
                          grf.remark
                     FROM {gradingform_rubric_fillings}  grf
                     JOIN {gradingform_rubric_criteria}  grc  ON grc.id  = grf.criterionid
                     JOIN {gradingform_rubric_levels}    gl   ON gl.id   = grf.levelid
                     JOIN {grading_instances}            gin  ON gin.id  = grf.instanceid
                                                              AND gin.status = 1
                     JOIN {assign_grades}                ag   ON ag.id   = gin.itemid
                     JOIN {grading_definitions}          gd   ON gd.id   = gin.definitionid
                     JOIN {grading_areas}                ga   ON ga.id   = gd.areaid
                     JOIN {context}                      ctx  ON ctx.id  = ga.contextid
                     JOIN {course_modules}               cm   ON cm.id   = ctx.instanceid
                                                              AND cm.id  = :cmid
                     JOIN {assign}                       asg  ON asg.id  = cm.instance
                LEFT JOIN {assignfeedback_comments}      afc  ON afc.grade = ag.id
                     JOIN {user}                         stu  ON stu.id  = ag.userid
                                                              AND stu.deleted = 0
                     JOIN {user}                         grdr ON grdr.id = ag.grader
                 ORDER BY stu.lastname, stu.firstname, grc.sortorder";

        $rawrows = $DB->get_records_sql($rawsql, ['cmid' => $cmid]);

        // PHP-level pivot: one entry per userid.
        $criteriamap = [];
        foreach ($criteria as $i => $criterion) {
            $criteriamap[$criterion->id] = $i + 1; // 1-based column index
        }

        $pivotrows = [];
        foreach ($rawrows as $row) {
            if (!isset($pivotrows[$row->userid])) {
                $studentobj = (object)[
                    'firstname'         => $row->firstname,
                    'lastname'          => $row->lastname,
                    'firstnamephonetic' => $row->firstnamephonetic ?? '',
                    'lastnamephonetic'  => $row->lastnamephonetic ?? '',
                    'middlename'        => $row->middlename ?? '',
                    'alternatename'     => $row->alternatename ?? '',
                ];
                $graderobj = (object)[
                    'firstname'         => $row->grader_firstname,
                    'lastname'          => $row->grader_lastname,
                    'firstnamephonetic' => $row->grader_firstnamephonetic ?? '',
                    'lastnamephonetic'  => $row->grader_lastnamephonetic ?? '',
                    'middlename'        => $row->grader_middlename ?? '',
                    'alternatename'     => $row->grader_alternatename ?? '',
                ];

                $pivotrow = (object)[
                    'userid'            => (int)$row->userid,
                    'student_firstname' => $row->firstname,
                    'student_lastname'  => $row->lastname,
                    'student_fullname'  => fullname($studentobj),
                    'student_email'     => $row->email,
                    'student_username'  => $row->username,
                    'student_idnumber'  => $row->idnumber,
                    'grade'             => ($row->grade !== null && $row->grade >= 0) ? (float)$row->grade : null,
                    'gradeoutof'        => $row->gradeoutof !== null ? (float)$row->gradeoutof : null,
                    'overallfeedback'   => $row->overallfeedback,
                    'grader_fullname'   => fullname($graderobj),
                    'timegraded'        => $row->timegraded !== null ? (int)$row->timegraded : null,
                ];

                // Initialise all criterion slots to null.
                foreach ($criteria as $i => $crit) {
                    $n = $i + 1;
                    $pivotrow->{"crit{$n}_score"}    = null;
                    $pivotrow->{"crit{$n}_leveldef"}  = null;
                    $pivotrow->{"crit{$n}_remark"}    = null;
                }

                $pivotrows[$row->userid] = $pivotrow;
            }

            // Fill criterion data into the student's pivot row.
            $n = $criteriamap[$row->criterionid] ?? null;
            if ($n !== null) {
                $pivotrows[$row->userid]->{"crit{$n}_score"}   = $row->score !== null ? (float)$row->score : null;
                $pivotrows[$row->userid]->{"crit{$n}_leveldef"} = $row->leveldef;
                $pivotrows[$row->userid]->{"crit{$n}_remark"}  = $row->remark;
            }
        }

        if (!empty($pivotrows)) {
            $DB->insert_records(self::TEMP_TABLE, array_values($pivotrows));
        }
    }

    /**
     * Register all report columns (static + dynamic per-criterion).
     *
     * @param string $alias    Table alias for the pivot temp table.
     * @param array  $criteria Ordered criteria objects.
     */
    private function define_columns(string $alias, array $criteria): void {

        // 1. Username.
        $this->add_column((new column(
            'student_fullname',
            new lang_string('fullnameuser', 'moodle'),
            self::ENTITY
        ))
            ->add_field("{$alias}.student_fullname")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true));

        // 2. Per-criterion columns (score / level definition / remark).
        foreach ($criteria as $i => $criterion) {
            $n    = $i + 1;
            $desc = $criterion->description;

            $this->add_column((new column(
                "crit{$n}_score",
                new lang_string(
                    'criterioncolumn',
                    'report_rubricgrading',
                    (object)['name' => $desc, 'col' => get_string('score', 'report_rubricgrading')]
                ),
                self::ENTITY
            ))
                ->add_field("{$alias}.crit{$n}_score")
                ->set_type(column::TYPE_FLOAT)
                ->set_is_sortable(true)
                ->add_callback(static function (?float $v): string {
                    return $v !== null ? format_float($v, 2) : '';
                }));

            $this->add_column((new column(
                "crit{$n}_leveldef",
                new lang_string(
                    'criterioncolumn',
                    'report_rubricgrading',
                    (object)['name' => $desc, 'col' => get_string('definition', 'report_rubricgrading')]
                ),
                self::ENTITY
            ))
                ->add_field("{$alias}.crit{$n}_leveldef")
                ->set_type(column::TYPE_LONGTEXT)
                ->set_is_sortable(false));

            $this->add_column((new column(
                "crit{$n}_remark",
                new lang_string(
                    'criterioncolumn',
                    'report_rubricgrading',
                    (object)['name' => $desc, 'col' => get_string('feedback', 'report_rubricgrading')]
                ),
                self::ENTITY
            ))
                ->add_field("{$alias}.crit{$n}_remark")
                ->set_type(column::TYPE_LONGTEXT)
                ->set_is_sortable(false));
        }

        // 3. Overall feedback.
        $this->add_column((new column(
            'overallfeedback',
            new lang_string('overallfeedback', 'report_rubricgrading'),
            self::ENTITY
        ))
            ->add_field("{$alias}.overallfeedback")
            ->set_type(column::TYPE_LONGTEXT)
            ->set_is_sortable(false));

        // 4. Grade.
        $this->add_column((new column(
            'grade',
            new lang_string('grade', 'report_rubricgrading'),
            self::ENTITY
        ))
            ->add_field("{$alias}.grade")
            ->set_type(column::TYPE_FLOAT)
            ->set_is_sortable(true)
            ->add_callback(static function (?float $v): string {
                return $v !== null ? format_float($v, 2) : '';
            }));

        // 5. Graded by.
        $this->add_column((new column(
            'grader_fullname',
            new lang_string('gradedby', 'report_rubricgrading'),
            self::ENTITY
        ))
            ->add_field("{$alias}.grader_fullname")
            ->set_type(column::TYPE_TEXT)
            ->set_is_sortable(true));

        // 6. Time graded.
        $this->add_column((new column(
            'timegraded',
            new lang_string('timegraded', 'report_rubricgrading'),
            self::ENTITY
        ))
            ->add_field("{$alias}.timegraded")
            ->set_type(column::TYPE_TIMESTAMP)
            ->set_is_sortable(true)
            ->add_callback([format::class, 'userdate']));
    }

    /**
     * Register report filters.
     *
     * Student identity filters reference temp-table fields that are not shown
     * as display columns; they are applied as WHERE conditions only.
     *
     * @param string $alias    Table alias for the pivot temp table.
     * @param array  $criteria Ordered criteria objects.
     */
    private function define_filters(string $alias, array $criteria): void {

        // Student identity.
        $this->add_filter((new filter(
            text::class,
            'student_firstname',
            new lang_string('firstname'),
            self::ENTITY,
            "{$alias}.student_firstname"
        )));

        $this->add_filter((new filter(
            text::class,
            'student_lastname',
            new lang_string('lastname'),
            self::ENTITY,
            "{$alias}.student_lastname"
        )));

        $this->add_filter((new filter(
            text::class,
            'student_email',
            new lang_string('email'),
            self::ENTITY,
            "{$alias}.student_email"
        )));

        $this->add_filter((new filter(
            text::class,
            'student_idnumber',
            new lang_string('idnumber'),
            self::ENTITY,
            "{$alias}.student_idnumber"
        )));

        // Grade / timing.
        $this->add_filter((new filter(
            number::class,
            'grade',
            new lang_string('grade', 'report_rubricgrading'),
            self::ENTITY,
            "{$alias}.grade"
        )));

        $this->add_filter((new filter(
            date::class,
            'timegraded',
            new lang_string('timegraded', 'report_rubricgrading'),
            self::ENTITY,
            "{$alias}.timegraded"
        )));

        // Per-criterion score filter.
        foreach ($criteria as $i => $criterion) {
            $n = $i + 1;
            $this->add_filter((new filter(
                number::class,
                "crit{$n}_score",
                new lang_string(
                    'criterioncolumn',
                    'report_rubricgrading',
                    (object)['name' => $criterion->description, 'col' => get_string('score', 'report_rubricgrading')]
                ),
                self::ENTITY,
                "{$alias}.crit{$n}_score"
            )));
        }
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
