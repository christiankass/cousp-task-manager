<?php

return [
    // Active PostgreSQL Row Level Security en complément du filtrage applicatif.
    'rls_enabled' => env('TENANCY_RLS_ENABLED', true),
];
