<?php

return [
    // Adresse de l'application web, utilisée dans les liens des e-mails.
    'web_url' => env('WEB_URL', 'http://localhost:3000'),

    // Envoi d'une copie des notifications par e-mail.
    'mail_notifications' => env('MAIL_NOTIFICATIONS', true),

    // Délai, en heures, avant l'échéance pour le rappel « échéance proche ».
    'due_soon_hours' => env('TASK_DUE_SOON_HOURS', 24),

    // Notifications push vers l'application mobile (service Expo).
    'push_notifications' => env('PUSH_NOTIFICATIONS', true),
    'expo_push_url' => env('EXPO_PUSH_URL', 'https://exp.host/--/api/v2/push/send'),
    'expo_access_token' => env('EXPO_ACCESS_TOKEN'),

    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'local'),
        'max_kb' => env('ATTACHMENTS_MAX_KB', 20480),
        'mimes' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'jpg', 'jpeg', 'png', 'zip'],
    ],
];
