<?php
require_once __DIR__ . '/../app/service/ScheduleService.php';
use app\service\ScheduleService;
date_default_timezone_set('Asia/Shanghai');
$checks = 0;
function calendarAssert($actual, $expected, $message) {
    global $checks;
    if ($actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true));
    $checks++;
}
function calendarNext($now, $cycle, $day, $time = '08:30') {
    return date('Y-m-d H:i:s', ScheduleService::calculateNextTime(['type' => 1, 'cycle' => $cycle, 'switchdate' => $day, 'switchtime' => $time, 'switchtype' => 0], strtotime($now)));
}
calendarAssert(calendarNext('2026-02-01 00:00', 2, 31), '2026-02-28 08:30:00', 'February31 clamps within February');
calendarAssert(calendarNext('2028-02-01 00:00', 2, 29), '2028-02-29 08:30:00', 'Leap February29');
calendarAssert(calendarNext('2026-02-28 08:30', 2, 31), '2026-03-31 08:30:00', 'No monthly date drift after clamped occurrence');
calendarAssert(calendarNext('2026-04-29 00:00', 2, 31), '2026-04-30 08:30:00', 'April31 clamps');
calendarAssert(calendarNext('2026-12-31 09:00', 2, 30), '2027-01-30 08:30:00', 'Year rollover');
calendarAssert(calendarNext('2026-10-19 08:30', 1, 1), '2026-10-26 08:30:00', 'Completed weekly exact boundary advances');
calendarAssert(calendarNext('2026-10-19 08:29', 1, 1), '2026-10-19 08:30:00', 'Upcoming same weekday');
calendarAssert(calendarNext('2026-10-19 08:30', 0, null), '2026-10-20 08:30:00', 'Daily boundary advances');
$once = ['type'=>0,'cycle'=>0,'switchtype'=>1,'switchtime'=>'2026-10-19T15:30'];
calendarAssert(ScheduleService::calculateNextTime($once, strtotime('2026-10-19 15:29')), strtotime('2026-10-19 15:30'), 'datetime-local T supported');
calendarAssert(ScheduleService::calculateNextTime($once, strtotime('2026-10-19 15:30')), 0, 'Past one-shot cannot become future');
foreach ([['type'=>[1]],['cycle'=>[]],['switchtype'=>'invalid'],['cycle'=>2,'switchdate'=>0],['cycle'=>2,'switchdate'=>32],['cycle'=>1,'switchdate'=>7],['switchtime'=>'24:00']] as $bad) {
    try { ScheduleService::validate(array_merge(['type'=>1,'cycle'=>0,'switchdate'=>1,'switchtime'=>'08:30','switchtype'=>0], $bad)); throw new RuntimeException('Invalid calendar accepted'); }
    catch (InvalidArgumentException $expected) { $checks++; }
}
try { ScheduleService::validate(array_merge($once,['switchtime'=>'2026-02-31T12:00'])); throw new RuntimeException('Rollover date accepted'); }
catch (InvalidArgumentException $expected) { $checks++; }
echo "Calendar checks passed: $checks\n";
