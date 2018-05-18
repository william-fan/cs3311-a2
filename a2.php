<?php
// COMP3311 18s1 Assignment 2
// Functions for assignment Tasks A-E
// Written by William Fan (z5059967), May 2018

// assumes that defs.php has already been included


// Task A: get members of an academic object group

// E.g. list($type,$codes) = membersOf($db, 111899)
// Inputs:
//  $db = open database handle
//  $groupID = acad_object_group.id value
// Outputs:
//  array(GroupType,array(Codes...))
//  GroupType = "subject"|"stream"|"program"
//  Codes = acad object codes in alphabetical order
//  e.g. array("subject",array("COMP2041","COMP2911"))

function membersOf($db, $groupID)
{
    $q = "select * from acad_object_groups where id = %d";
    $grp = dbOneTuple($db, mkSQL($q, $groupID));
    $output = array();

    if ($grp["gdefby"] == "pattern") {
        $courseList = preg_split("/,/", $grp["definition"]);
        $courseList = getPattern($db, $courseList, $grp["gtype"], false);
    } elseif ($grp["gdefby"] == "enumerated") {
        $courseList = getEnum($db, $grp["gtype"], $grp["id"]);
    } elseif ($grp["gdefby"] == "query") {
        $courseList = getQuery($db, $grp["gtype"], $grp["definition"]);
    }
    return array($grp["gtype"], $courseList);
}

function membersOfExpanded($db, $groupID)
{
    $q = "select * from acad_object_groups where id = %d";
    $grp = dbOneTuple($db, mkSQL($q, $groupID));
    $output = array();

    if ($grp["gdefby"] == "pattern") {
        $courseList = preg_split("/,/", $grp["definition"]);
        $courseList = getPattern($db, $courseList, $grp["gtype"], true);
    } elseif ($grp["gdefby"] == "enumerated") {
        $courseList = getEnum($db, $grp["gtype"], $grp["id"]);
    } elseif ($grp["gdefby"] == "query") {
        $courseList = getQuery($db, $grp["gtype"], $grp["definition"]);
    }
    return ($courseList);
}

function getEnum($db, $type, $id)
{
    $acadObjectIds = array();   // declare in case of nothing found
    $idList = array();
    $courseList = array();

    $subGrpQry = <<<xxSQLxx
        select id
        from acad_object_groups 
        where parent = %s;
xxSQLxx;
    $subGrpQryRes = dbQuery($db, mkSQL($subGrpQry, $id)); // look for subgroups of this id
    $acadObjectIds[] = $id;  // add parent id 
    while ($t = dbNext($subGrpQryRes)) {
        $acadObjectIds[] = $t["id"];
    }

    foreach ($acadObjectIds as &$acadId) {
        if ($type == "program") {
            $qry = <<<xxSQLxx
                select program
                from Program_group_members 
                where ao_group = %s;
xxSQLxx;
            $secQry = <<<xxSQLxx
                select code
                from Programs
                where id = %s;
xxSQLxx;
        } elseif ($type == "stream") {
            $qry = <<<xxSQLxx
                select stream
                from Stream_group_members 
                where ao_group = %s;
xxSQLxx;
            $secQry = <<<xxSQLxx
                select code
                from Streams
                where id = %s;
xxSQLxx;
        } elseif ($type == "subject") {
            $qry = <<<xxSQLxx
                select subject
                from Subject_group_members 
                where ao_group = %s;
xxSQLxx;
            $secQry = <<<xxSQLxx
                select code
                from Subjects
                where id = %s;
xxSQLxx;
        }

        $res = dbQuery($db, mkSQL($qry, $acadId));
        while ($t = dbNext($res)) { // store results
            $idList[] = $t[$type];
        }
    }
    foreach ($idList as &$member) {
        $secRes = dbOneTuple($db, mkSQL($secQry, $member));
        $courseList[] = $secRes["code"];
    }
    unset($acadId);
    unset($member);
    asort($courseList);
    return $courseList;
}

function getQuery($db, $type, $definition)
{
    $courseList = array();
    $res = dbQuery($db, mkSQL($definition));
    while ($t = dbNext($res)) { // store results
        $courseList[] = $t["code"];
    }

    asort($courseList);
    return $courseList;
}

function getPattern($db, $patternList, $type, $expanded)
{
    $courseList = array();
    foreach ($patternList as &$pattern) {
        if (!$expanded) {
            if (preg_match("/^[A-Z]{4}[0-9]{4}$|^FREE.{4}|^GENG.{4}|^GEN#.{4}|^####.{4}|^all|^ALL|^[0-9]{4}$|^[A-Z]{5}[0-9]$/", $pattern)) {
                $courseList[] = $pattern;
            } elseif (preg_match("/.*(#|\[|\|).*|^{.*}$|^!.*|.*F=.*/", $pattern)) {  // search for regex
                $courseList = array_merge($courseList, sqlRegexQuery($db, $pattern));
            } else {
                $courseList[] = $pattern;
            }
        } else {
            $courseList = array_merge($courseList, sqlRegexQuery($db, $pattern));
        }
    }
    unset($pattern);
    asort($courseList);
    return $courseList;
}

function sqlRegexQuery($db, $inputPattern)
{
    $pattern = str_replace(array('#', 'x'), array('.', '.'), $inputPattern);     // replace # codes
    $pattern = str_replace(array('{', '}', ';'), array('(', ')', '|'), $pattern); // replace {;} codes
    $pattern = str_replace("FREE", "....", $pattern);
    $pattern = str_replace("all", ".......", $pattern);
    $pattern = str_replace("ALL", ".......", $pattern);
    $pattern = str_replace("GENG", "GEN.", $pattern);
    $output = array();

    if (preg_match("/^!.*/", $pattern) && preg_match("/.*F=([A-Z]+)\/?/", $pattern, $facid)) {
        $pattern = substr($pattern, 0, strpos($pattern, "/F="));
        $qry = <<<xxSQLxx
            select Subjects.code
            from Subjects 
            join OrgUnits on Subjects.offeredby = OrgUnits.id
            where Subjects.code !~ %s and OrgUnits.unswid = %s
            order by Subjects.code;
xxSQLxx;
        $res = dbQuery($db, mkSQL($qry, $pattern, $facid[1]));
    } elseif (preg_match("/^!.*/", $pattern)) {
        $qry = <<<xxSQLxx
            select Subjects.code
            from Subjects 
            where Subjects.code !~ %s
            order by Subjects.code;
xxSQLxx;
    } elseif (preg_match("/.*F=([A-Z]+)\/?/", $pattern, $facid)) {
        $pattern = substr($pattern, 0, strpos($pattern, "/F="));
        $qry = <<<xxSQLxx
            select Subjects.code
            from Subjects
            join OrgUnits on Subjects.offeredby = OrgUnits.id
            where Subjects.code ~ %s and OrgUnits.unswid = %s
            order by Subjects.code;
xxSQLxx;
        $res = dbQuery($db, mkSQL($qry, $pattern, $facid[1]));
    } else {
        $qry = <<<xxSQLxx
            select Subjects.code
            from Subjects 
            where Subjects.code ~ %s
            order by Subjects.code;
xxSQLxx;
        $res = dbQuery($db, mkSQL($qry, $pattern));
    }

    while ($t = dbNext($res)) { // store results
        $output[] = $t["code"];
    }
    return $output;
}

// Task B: check if given object is in a group

// E.g. if (inGroup($db, "COMP3311", 111938)) ...
// Inputs:
//  $db = open database handle
//  $code = code for acad object (program,stream,subject)
//  $groupID = acad_object_group.id value
// Outputs:
//  true/false

function inGroup($db, $code, $groupID)
{
    $q = "select * from acad_object_groups where id = %d";
    $grp = dbOneTuple($db, mkSQL($q, $groupID));
    if ($grp["gdefby"] == "pattern") {
        $courseList = preg_split("/,/", $grp["definition"]);
        $courseList = getPattern($db, $courseList, $grp["gtype"], false);
        foreach ($courseList as &$course) {
            if ($code == $course) return true;

            if (preg_match("/^FREE.{4}|^GENG.{4}|^GEN#.{4}|^####.{4}|^all|^ALL/", $course)) {
                // all don't include gens
                if (preg_match("/^all$|^ALL$/", $course) && !preg_match("/^GEN.{5}/", $code)) {
                    return true;
                }
                if (preg_match("/^FREE.{4}|^####.{4}|^all.*|^ALL.*/", $course) && preg_match("/^GEN.{5}/", $code)) {
                    continue;
                }
                // now include patterns not used in a
                if (preg_match("/^GENG.{4}/", $course)) { // GENG == GEN
                    $course = str_replace("GENG", "GEN.", $course);
                }
                if (preg_match("/^FREE.{4}/", $course)) { // FREE is any course but gen
                    $course = str_replace("FREE", "....", $course);
                }
                if (preg_match("/^all.*|^ALL.*/", $course)) { // all but with /F=
                    $course = str_replace("all", ".......", $course);
                    $course = str_replace("ALL", ".......", $course);
                }

                $outputList = sqlRegexQuery($db, $course);
                foreach ($outputList as &$newCourse) {
                    if ($code == $newCourse) return true;
                }
            } else { // pattern is not a gen or free
                $outputList = sqlRegexQuery($db, $course);
                foreach ($outputList as &$newCourse) {
                    if ($code == $newCourse) return true;
                }
            }
        }
        return false;
    } elseif ($grp["gdefby"] == "enumerated") {
        $courseList = getEnum($db, $grp["gtype"], $grp["id"]);
        foreach ($courseList as &$course) {
            if ($code == $course) {
                return true;
            }
        }
        return false;
    } elseif ($grp["gdefby"] == "query") {
        $courseList = getQuery($db, $grp["gtype"], $grp["definition"]);
        foreach ($courseList as &$course) {
            if ($code == $course) {
                return true;
            }
        }
        return false;
    } else {
        return false;
    }
}


// Task C: can a subject be used to satisfy a rule

// E.g. if (canSatisfy($db, "COMP3311", 2449, $enr)) ...
// Inputs:
//  $db = open database handle
//  $code = code for acad object (program,stream,subject)
//  $ruleID = rules.id value
//  $enr = array(ProgramID,array(StreamIDs...))
// Outputs:

function canSatisfy($db, $code, $ruleID, $enrolment)
{
    $q = "select * from Rules where id = %d";
    $rule = dbOneTuple($db, mkSQL($q, $ruleID));

    if ($rule["ao_group"] != "") { // check for associated acad group
        $q = "select * from acad_object_groups where id = %d";
        $acadObject = dbOneTuple($db, mkSQL($q, $rule["ao_group"]));
    } else {
        return false;
    }

    if ($acadObject["id"] == "") {  // if acad group is empty
        return false;
    }

    if ($rule["type"] == "DS") { // obj group must be same type as rule
        if ($acadObject["gtype"] != "stream") {
            return false;
        }
    } else if (in_array($rule["type"], array("LR", "MR", "WM", "IR"))) { // invalid codes
        return false;
    } else {
        if ($acadObject["gtype"] != "subject") {
            return false;
        }
    }

    // checks done
    if (!preg_match("/^GEN.*/", $code)) {  // gen ed special cases
        return inGroup($db, $code, $acadObject["id"]);
    }

    // now find if gen ed is in same faculty
    // get faculties of streams and program
    $q = <<<xxSQLxx
        select offeredby
        from Programs
        where id = %s
xxSQLxx;
    $result = dbOneTuple($db, mkSQL($q, $enrolment[0]));
    $enrolmentFaculties[] = findOwnerOf($db, $result["offeredby"]);

    $q = <<<xxSQLxx
        select offeredby
        from Streams
        where id = %s
xxSQLxx;
    foreach ($enrolment[1] as &$stream) {  // now find owner faculty of streams
        $result = dbOneTuple($db, mkSQL($q, $stream));
        $owner = findOwnerOf($db, $result["offeredby"]);
        if (!in_array($owner, $enrolmentFaculties)) {
            $enrolmentFaculties[] = $owner;
        }
    }

    // get owner faculty of input code
    $q = <<<xxSQLxx
        select offeredby
        from Subjects
        where code = %s
xxSQLxx;
    $result = dbOneTuple($db, mkSQL($q, $code));
    $facultyOfCode = findOwnerOf($db, $result["offeredby"]);
    foreach ($enrolmentFaculties as &$faculty) {  // check if faculty is the same as code
        if ($facultyOfCode == $faculty) return false;
    }
    return true;
}

function findOwnerOf($db, $code)
{
    $q = "select * from OrgUnit_groups where member = %s";
    $result = dbOneTuple($db, mkSQL($q, $code));
    if ($result["owner"] == "0") {
        return $code;
    } else {
        return $result["owner"];
    }
}

// Task D: determine student progress through a degree

// E.g. $vtrans = progress($db, 3012345, "05s1");
// Inputs:
//  $db = open database handle
//  $stuID = People.unswid value (i.e. unsw student id)
//  $semester = code for semester (e.g. "09s2")
// Outputs:
//  Virtual transcript array (see spec for details)

function progress($db, $stuID, $term)
{
    $courseList = array();
    $output = array();
    $tempList = array();
    $q = "select * from transcript(%s, %s)";
    $res = dbQuery($db, mkSQL($q, $stuID, $term));
    while ($t = dbNext($res)) {  // last line is wam
        if ($t["code"] == null) {
            $wam = $t;
        } else {
            $courseList[] = $t;
        }
    }

    $ruleList = getRules($db, $stuID, $term);
    if (empty($ruleList)) return array();  // no rules found
    $allocations = allocateCourses($db, $ruleList, $courseList);

    foreach ($allocations["course"] as &$courses) {
        $output[] = $courses;
    }

    $output[] = array("Overall WAM", $wam["mark"], $wam["uoc"]);
    $tempList = array_merge($allocations["CC"], $allocations["PE"], $allocations["FE"], $allocations["GE"], $allocations["LR"]);
    foreach ($tempList as &$temp) {
        if ($temp["remaining"] != 0) $output[] = array($temp["sofar"] . " UOC so far; need " . $temp["remaining"] . " UOC more", $temp["name"]);
    }
    return $output;
}

function allocateCourses($db, $ruleList, $courseList)
{
    $allocations = array();
    $allocations["CC"] = array();
    $allocations["PE"] = array();
    $allocations["FE"] = array();
    $allocations["GE"] = array();
    $allocations["LR"] = array();

    foreach ($ruleList as &$rule) {  // organise rules based on type
        $rule["remaining"] = $rule["min"];
        if ($rule["min"] == null) $rule["remaining"] = 0;
        $rule["sofar"] = 0;
        $allocations[$rule["type"]][] = $rule;
    }
    foreach ($courseList as &$course) {  // match course to requirement
        if (!$course["grade"]) { // uncompleted course
            $allocations["course"][] = array($course["code"], $course["term"], $course["name"], null, null,
                null, "Incomplete. Does not yet count");
            continue;
        } elseif ($course["grade"] == "FL") {  // fail
            $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                $course["grade"], "0", "Failed. Does not count");
            continue;
        }
        foreach ($allocations["LR"] as &$LRrule) {
            if (empty($$LRrule["id"])) $$LRrule["id"] = membersOfExpanded($db, $LRrule["ao_group"]);
            if (in_array($course["code"], $$LRrule["id"])) {
                if ($LRrule["max"] <= $LRrule["sofar"] + $course["uoc"] && $LRrule["max"] != null) {  // limit rule reached
                    $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                        $course["grade"], $course["uoc"], "Fits no requirement. Does not count");
                    continue 2;
                } else {
                    $LRrule["sofar"] += $course["uoc"];
                }
            }
        }
        if (preg_match("/^GEN.*/", $course["code"])) {  //gen ed
            foreach ($allocations["GE"] as &$GErule) {
                if (empty($$GErule["id"])) $$GErule["id"] = membersOfExpanded($db, $GErule["ao_group"]);
                if (in_array($course["code"], $$GErule["id"]) && $GErule["remaining"] - $course["uoc"] >= 0) {
                    if ($GErule["sofar"] + $course["uoc"] <= $GErule["max"] || $GErule["max"] == null) {
                        $GErule["remaining"] -= $course["uoc"];
                        $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                            $course["grade"], $course["uoc"], $GErule["name"]);
                        $GErule["sofar"] += $course["uoc"];
                        continue 2;
                    }
                }
            }
            $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                $course["grade"], null, "Fits no requirement. Does not count");
            continue;  // couldnt match gen ed course
        } else {  // try to match to rule
            foreach ($allocations["CC"] as &$CCrule) {
                if (empty($$CCrule["id"])) $$CCrule["id"] = membersOfExpanded($db, $CCrule["ao_group"]);
                if (in_array($course["code"], $$CCrule["id"]) && $CCrule["remaining"] - $course["uoc"] >= 0) {
                    if ($CCrule["sofar"] + $course["uoc"] <= $CCrule["max"] || $CCrule["max"] == null) {
                        $CCrule["remaining"] -= $course["uoc"];
                        $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                            $course["grade"], $course["uoc"], $CCrule["name"]);
                        $CCrule["sofar"] += $course["uoc"];
                        continue 2;
                    }
                }
            }
            foreach ($allocations["PE"] as &$PErule) {
                if (empty($$PErule["id"])) $$PErule["id"] = membersOfExpanded($db, $PErule["ao_group"]);
                if (in_array($course["code"], $$PErule["id"]) && $PErule["remaining"] - $course["uoc"] >= 0) {
                    if ($PErule["sofar"] + $course["uoc"] <= $PErule["max"] || $PErule["max"] == null) {
                        $PErule["remaining"] -= $course["uoc"];
                        $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                            $course["grade"], $course["uoc"], $PErule["name"]);
                        $PErule["sofar"] += $course["uoc"];
                        continue 2;
                    }
                }
            }
            foreach ($allocations["FE"] as &$FErule) {
                if (empty($$FErule["id"])) $$FErule["id"] = membersOfExpanded($db, $FErule["ao_group"]);
                if (in_array($course["code"], $$FErule["id"]) && $FErule["remaining"] - $course["uoc"] >= 0) {
                    if ($FErule["sofar"] + $course["uoc"] <= $FErule["max"] || $FErule["max"] == null) {
                        $FErule["remaining"] -= $course["uoc"];
                        $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                            $course["grade"], $course["uoc"], $FErule["name"]);
                        $FErule["sofar"] += $course["uoc"];
                        continue 2;
                    }
                }
            }
            $allocations["course"][] = array($course["code"], $course["term"], $course["name"], $course["mark"],
                $course["grade"], $course["uoc"], "Fits no requirement. Does not count");
        }
    }
    return $allocations;
}

function getRules($db, $stuID, $term)
{
    $ruleList = array();
    $streamList = array();
    $q = "select Program_enrolments.id, program, code from Program_enrolments join Programs on program = Programs.id where student=%d and semester=%d"; //get program
    $pe = dbOneTuple($db, mkSQL($q, $stuID, $term));

    if (empty($pe)) $pe = getClosestSem($db, $stuID);
    if (empty($pe)) return array();  // no enrolments

    $q = "select stream from Stream_enrolments where partof=%d";  // get streams
    $r = dbQuery($db, mkSQL($q, $pe["id"]));
    while ($t = dbNext($r)) {
        $streamList[] = $t["stream"];
    }

    $q = "select * from program_rules join rules on program_rules.rule=rules.id where program = %s order by rules.id"; // get rules from program
    $rules = dbQuery($db, mkSQL($q, $pe["program"]));
    while ($t = dbNext($rules)) {
        $ruleList[] = $t;
    }

    $q = "select * from stream_rules join rules on stream_rules.rule=rules.id where stream = %s order by rules.id"; // get rules from stream
    foreach ($streamList as &$stream) {
        $rules = dbQuery($db, mkSQL($q, $stream));
        while ($t = dbNext($rules)) {
            $ruleList[] = $t;
        }
    }
    return $ruleList;
}

// unused
function unswIdToPeople($db, $stuID)
{
    $q = "select * from People where unswid = %s";
    $result = dbOneTuple($db, mkSQL($q, $stuID));
    return $result["id"];
}

// unused
function semesterToId($db, $semester)
{
    $semesterArray = str_split($semester, 2);
    $semesterYear = "20" . $semesterArray[0];
    $semesterTerm = strtoupper($semesterArray[1]);
    $q = "select * from Semesters where year = %s and term = %s";
    $result = dbOneTuple($db, mkSQL($q, $semesterYear, $semesterTerm));
    return $result["id"];
}

function getClosestSem($db, $stuID)
{
    $q = <<<xxSQLxx
    select Program_enrolments.id, program, code 
    from Program_enrolments 
    join Programs on program = Programs.id
    join Semesters on Program_enrolments.semester=semesters.id
    where student=%d
    order by ending desc
    limit 1;
xxSQLxx;
    $pe = dbOneTuple($db, mkSQL($q, $stuID));
    return $pe;
}

// Task E:

// E.g. $advice = advice($db, 3012345, 162, 164)
// Inputs:
//  $db = open database handle
//  $studentID = People.unswid value (i.e. unsw student id)
//  $currTermID = code for current semester (e.g. "09s2")
//  $nextTermID = code for next semester (e.g. "10s1")
// Outputs:
//  Advice array (see spec for details)

// get complete courses, get courses available next sem
// get rules and see if courses fulfill them
// get courses and see if prereqs are met

function advice($db, $studentID, $currTermID, $nextTermID)
{
    $completedCourses = array();
    $nextSemCourses = array();
    $ruleList = array();
    $remainingRules = array();
    $maturityRules = array();
    $output = array();
    $ruleList = getRules($db, $studentID, $currTermID);

    foreach ($ruleList as &$rule) {
        if ($rule["type"] == "MR") $maturityRules[] = $rule;
    }
    $uoc = 0;
    $q = "select * from transcript(%s, %s)";
    $res = dbQuery($db, mkSQL($q, $studentID, $currTermID));
    while ($t = dbNext($res)) {
        if ($t["code"] != null && !in_array($t["grade"], array("NF", "FL", "DF"))) {
            if ($t["mark"] == null || $t["grade"] == null) {  // assume non-failed current sem courses will be passed
                $t["mark"] = "50";
                $t["grade"] = "PS";
            }
            $completedCourses[] = $t;
            $uoc += $t["uoc"];
        }
    }

    $allocations = allocateCourses($db, $ruleList, $completedCourses);  // get unfilled rules
    $tempList = array_merge($allocations["CC"], $allocations["PE"]);

    foreach ($tempList as &$temp) {
        if ($temp["remaining"] != 0) $remainingRules[] = $temp;
    }

    $q = "select * from Courses join subjects on courses.subject=subjects.id where semester=%s;";
    $res = dbQuery($db, mkSQL($q, $nextTermID));
    while ($t = dbNext($res)) {
        $nextSemCourses[] = $t["code"];
    }
    if (empty($nextSemCourses)) $nextSemCourses = getClosestSemWithCourses($db, $nextTermID);  //check if next sem has no courses

    $output = connectRulesToCourses($db, getPossibleCourses($db, $studentID, $nextSemCourses, $remainingRules, $completedCourses, $currTermID, $nextTermID, $uoc, $maturityRules), $remainingRules);

    foreach ($allocations["FE"] as &$FERule) {
        if ($FERule["remaining"] != 0) $output[] = array("Free....", "Free Electives (many choices)", $FERule["remaining"], $FERule["name"]);
    }

    foreach ($allocations["GE"] as &$GErule) {
        if ($GErule["remaining"] != 0 && checkGenEdMaturity($db, $maturityRules, $uoc)) $output[] = array("GenEd...", "General Education (many choices)", $GErule["remaining"], $GErule["name"]);
    }

    return $output;
}

function getPossibleCourses($db, $studentID, $nextSemCourses, $ruleList, $completedCourses, $currTermID, $nextTermID, $uoc, $maturityRules)
{
    $ruleMembers = array();
    $ruleCourses = array();
    $courseDetails = array();
    $equivalentCourses = array();
    $finalList = array();
    $completedCodes = array();

    foreach ($ruleList as &$rule) {
        if (in_array($rule["type"], array("CC", "PE"))) {
            $ruleMembers[] = membersOf($db, $rule["ao_group"]);
        }
    }
    foreach ($ruleMembers as &$member) {
        if ($member[0] == "subject") {
            $ruleCourses = array_merge($ruleCourses, $member[1]);
        }
    }
    foreach ($completedCourses as &$completed) {
        $q = "select equivalent from subjects where code=%s";
        $res = dbOneTuple($db, mkSQL($q, $completed["code"]));
        $equivalentCourses[] = membersOf($db, $res);
        $completedCodes[] = $completed["code"];
    }

    $possibleCourses = array_intersect($ruleCourses, $nextSemCourses);  // get courses next sem and contribute to completion
    $possibleCourses = array_diff($possibleCourses, $completedCodes); // remove completed courses
    $possibleCourses = array_diff($possibleCourses, $equivalentCourses); // remove equivalent courses
    $possibleCourses = checkMaturityRules($db, $uoc, $maturityRules, $possibleCourses);

    foreach ($possibleCourses as &$course) {
        if (canStudentTake($db, $studentID, $completedCodes, $course) && !in_array($course, $finalList)) $finalList[] = $course;
    }

    $q = "select * from Courses join subjects on courses.subject=subjects.id where semester=%s and code=%s;";
    foreach ($finalList as &$course) { // add details back to courses
        $res = dbOneTuple($db, mkSQL($q, $nextTermID, $course));
        $courseDetails[] = $res;
    }
    return $courseDetails;
}

function checkMaturityRules($db, $uoc, $ruleList, $possibleCourses)
{
    foreach ($ruleList as &$rule) {
        if ($uoc >= $rule["min"]) {
            continue;
        } else {
            foreach ($possibleCourses as &$course) {
                if (empty($$rule["id"])) $$rule["id"] = membersOfExpanded($db, $rule["ao_group"]);
                if (in_array($course, $$rule["id"])) {
                    $possibleCourses = array_diff($possibleCourses, array($course));
                }
            }
        }
    }
    return $possibleCourses;
}

function canStudentTake($db, $studentID, $completedCourses, $code)
{
    $career = getStudentCareer($db, $studentID);
    $prereqs = getCoursePrereqs($db, $code, $career);

    $q = "select excluded from subjects where code=%s;";
    $res = dbOneTuple($db, mkSQL($q, $code));
    foreach ($completedCourses as &$complete) {   // check if excluded course
        if (inGroup($db, $complete, $res["excluded"])) return false;
    }

    $q = "select * from Subjects where code=%s";  // check if career is the same as the subject
    $res = dbOneTuple($db, mkSQL($q, $code));
    if (empty($prereqs[0]) && $res["career"] != $career) return false;  // if no prereqs and different career, we can't take it

    foreach ($prereqs as &$pre) { // is prereq met?
        foreach ($pre as &$p) {
            if (in_array($p, $completedCourses)) {
                $pre["possible"] = true;
            }
        }
    }

    foreach ($prereqs as &$pre) {  // are there still missing prereqs?
        foreach ($pre as &$p) {
            if ($pre["possible"] == false) {
                return false;
            }
        }
    }
    return true;
}

function getStudentCareer($db, $studentID)
{
    $q = "select career from Program_enrolments join programs on program=programs.id join semesters on semester=semesters.id where student=%s order by ending desc limit 1";
    $res = dbOneTuple($db, mkSQL($q, $studentID));
    return $res["career"];
}

function getCoursePrereqs($db, $code, $career)
{
    $q = "select ao_group from subject_prereqs join rules on rule=rules.id join subjects on subject=subjects.id where code=%s and subject_prereqs.career=%s;";
    $res = dbQuery($db, mkSQL($q, $code, $career));
    $aoGroups = array();
    $aoGroups[] = array();
    $count = 0;
    while ($t = dbNext($res)) {
        list($temp, $tempGroup) = membersOf($db, $t["ao_group"]);
        if ($temp) $aoGroups[$count] = $tempGroup;
        if (!empty($aoGroups[$count])) $aoGroups[$count]["possible"] = false;
        $count++;
    }
    return $aoGroups;
}

// if sem has no courses get latest sem with courses and same term
function getClosestSemWithCourses($db, $semID)
{
    $chosenSemCourses = array();
    $q = "select semester from Courses join Semesters on semester=Semesters.id where term = (select term from Semesters where id=%s) order by starting desc limit 1;";
    $sem = dbOneTuple($db, mkSQL($q, $semID));

    $q = "select * from Courses join subjects on courses.subject=subjects.id where semester=%s;";
    $res = dbQuery($db, mkSQL($q, $sem["semester"]));
    while ($t = dbNext($res)) {
        $chosenSemCourses[] = $t["code"];
    }
    return $chosenSemCourses;
}

function connectRulesToCourses($db, $courses, $rules)
{
    $output = array();
    foreach ($courses as &$course) {
        foreach ($rules as &$rule) {
            if (empty($$rule["id"])) $$rule["id"] = membersOfExpanded($db, $rule["ao_group"]);
            if (in_array($course["code"], $$rule["id"])) {
                $output[] = array($course["code"], $course["name"], $course["uoc"], $rule["name"]);
                continue 2;
            }
        }
    }
    return $output;
}

function checkGenEdMaturity($db, $maturityRules, $uoc)
{
    $q = "select definition from acad_object_groups where id=%s";
    foreach ($maturityRules as $rule) {
        $res = dbOneTuple($db, mkSQL($q, $rule["ao_group"]));
        if (preg_match("/^GEN.*/", $res["definition"]) && $uoc >= $rule["min"]) {
            return true;
        } elseif (preg_match("/^GEN.*/", $res["definition"]) && $uoc < $rule["min"]) {
            return false;
        }
    }
    return true;  // no matching MR rules
}

?>
