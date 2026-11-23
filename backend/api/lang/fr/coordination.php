<?php

return [
    'event_type' => [
        'cholera' => 'Choléra', 'measles' => 'Rougeole', 'mpox' => 'Mpox', 'hemorrhagic_fever' => 'Fièvre hémorragique',
        'flood' => 'Inondation à risque sanitaire', 'other' => 'Autre événement de santé publique',
    ],
    'severity' => ['low' => 'Faible', 'moderate' => 'Modérée', 'high' => 'Élevée', 'critical' => 'Critique'],
    'event_status' => ['active' => 'Riposte en cours', 'monitoring' => 'Sous surveillance', 'closed' => 'Clôturé'],
    'meeting_type' => [
        'coordination' => 'Réunion de coordination', 'technical' => 'Réunion technique', 'management' => 'Comité de direction',
        'crisis' => 'Cellule de crise', 'other' => 'Autre réunion',
    ],
    'meeting_status' => ['planned' => 'Prévue', 'held' => 'Tenue', 'cancelled' => 'Annulée'],
    'recommendation_status' => [
        'not_converted' => 'Pas encore en tâche', 'in_progress' => 'En cours', 'overdue' => 'En retard',
        'done' => 'Exécutée', 'cancelled' => 'Abandonnée',
    ],
    'mission_status' => [
        'planned' => 'Prévue', 'in_progress' => 'En cours', 'report_submitted' => 'Rapport en validation', 'completed' => 'Terminée',
    ],
    'event_closed' => 'Cet événement est clôturé : on ne peut plus y rattacher de nouvelles actions.',
    'event_already_closed' => 'Cet événement est déjà clôturé.',
    'recommendation_cancelled' => 'Cette recommandation a été abandonnée.',
    'responsible_required' => 'Désignez un responsable pour la tâche.',
    'unit_required' => 'Choisissez la structure chargée de la tâche.',
    'task_from_recommendation' => 'Recommandation :reference — réunion « :meeting ».',
    'mission_task_title' => 'Mission :reference — :destination',
    'mission_expected_result' => 'Rapport de mission déposé et validé.',
    'leader_already_in_team' => 'Le chef de mission fait déjà partie de l\'équipe.',
    'only_leader_reports' => 'Seul le chef de mission peut déposer le rapport.',
    'report_submitted_comment' => 'Rapport de la mission :reference déposé.',
    'participant_required' => 'Indiquez un utilisateur ou le nom d\'un participant externe.',
];
