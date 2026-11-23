<?php

return [
    'event_type' => [
        'cholera' => 'Cholera', 'measles' => 'Measles', 'mpox' => 'Mpox', 'hemorrhagic_fever' => 'Haemorrhagic fever',
        'flood' => 'Flood with health risk', 'other' => 'Other public health event',
    ],
    'severity' => ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High', 'critical' => 'Critical'],
    'event_status' => ['active' => 'Response ongoing', 'monitoring' => 'Under monitoring', 'closed' => 'Closed'],
    'meeting_type' => [
        'coordination' => 'Coordination meeting', 'technical' => 'Technical meeting', 'management' => 'Management committee',
        'crisis' => 'Crisis cell', 'other' => 'Other meeting',
    ],
    'meeting_status' => ['planned' => 'Planned', 'held' => 'Held', 'cancelled' => 'Cancelled'],
    'recommendation_status' => [
        'not_converted' => 'Not yet a task', 'in_progress' => 'In progress', 'overdue' => 'Overdue',
        'done' => 'Completed', 'cancelled' => 'Dropped',
    ],
    'mission_status' => [
        'planned' => 'Planned', 'in_progress' => 'In progress', 'report_submitted' => 'Report under review', 'completed' => 'Completed',
    ],
    'event_closed' => 'This event is closed: no new actions can be linked to it.',
    'event_already_closed' => 'This event is already closed.',
    'recommendation_cancelled' => 'This recommendation was dropped.',
    'responsible_required' => 'Choose a lead for the task.',
    'unit_required' => 'Choose the unit in charge of the task.',
    'task_from_recommendation' => 'Recommendation :reference — meeting “:meeting”.',
    'mission_task_title' => 'Mission :reference — :destination',
    'mission_expected_result' => 'Mission report submitted and approved.',
    'leader_already_in_team' => 'The mission lead is already part of the team.',
    'only_leader_reports' => 'Only the mission lead can submit the report.',
    'report_submitted_comment' => 'Report for mission :reference submitted.',
    'participant_required' => 'Give a user or the name of an external participant.',
];
