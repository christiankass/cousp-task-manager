<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tasks:detect-deadlines')->everyFifteenMinutes()->withoutOverlapping();
