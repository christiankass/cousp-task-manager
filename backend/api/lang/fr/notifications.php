<?php

return [
    'system' => 'Le système',
    'mail_greeting' => 'Bonjour :name,',
    'mail_action' => 'Ouvrir la tâche',
    'task_assigned' => ['title' => 'Nouvelle tâche : :reference', 'message' => ':actor vous a confié « :title ». Échéance : :due.'],
    'task_updated' => ['title' => 'Tâche modifiée : :reference', 'message' => ':actor a modifié « :title ». :changes'],
    'task_due_soon' => ['title' => 'Échéance proche : :reference', 'message' => '« :title » arrive à échéance le :due.'],
    'task_overdue' => ['title' => 'Tâche en retard : :reference', 'message' => '« :title » a dépassé son échéance du :due.'],
    'task_submitted' => ['title' => 'À valider : :reference', 'message' => ':actor a soumis « :title » pour validation.'],
    'task_rejected' => ['title' => 'Corrections demandées : :reference', 'message' => ':actor a demandé des corrections sur « :title » : :comments'],
    'task_validated' => ['title' => 'Tâche validée : :reference', 'message' => '« :title » a été validée.'],
    'task_commented' => ['title' => 'Nouveau commentaire : :reference', 'message' => ':actor a commenté « :title ».'],
    'task_critical' => ['title' => 'Tâche critique : :reference', 'message' => ':actor a créé la tâche critique « :title ». Échéance : :due.'],
];
