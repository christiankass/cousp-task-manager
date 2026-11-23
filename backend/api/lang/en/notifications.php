<?php

return [
    'system' => 'The system',
    'mail_greeting' => 'Hello :name,',
    'mail_action' => 'Open the task',
    'task_assigned' => ['title' => 'New task: :reference', 'message' => ':actor assigned you “:title”. Due: :due.'],
    'task_updated' => ['title' => 'Task updated: :reference', 'message' => ':actor updated “:title”. :changes'],
    'task_due_soon' => ['title' => 'Due soon: :reference', 'message' => '“:title” is due on :due.'],
    'task_overdue' => ['title' => 'Overdue task: :reference', 'message' => '“:title” passed its due date of :due.'],
    'task_submitted' => ['title' => 'To approve: :reference', 'message' => ':actor submitted “:title” for approval.'],
    'task_rejected' => ['title' => 'Corrections requested: :reference', 'message' => ':actor requested corrections on “:title”: :comments'],
    'task_validated' => ['title' => 'Task approved: :reference', 'message' => '“:title” has been approved.'],
    'task_commented' => ['title' => 'New comment: :reference', 'message' => ':actor commented on “:title”.'],
    'task_critical' => ['title' => 'Critical task: :reference', 'message' => ':actor created the critical task “:title”. Due: :due.'],
];
