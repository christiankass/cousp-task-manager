<?php

namespace App\Enums;

/** Statut opérationnel d'une tâche (§4.3). Le retard est un indicateur distinct. */
enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case OnHold = 'on_hold';
    case Submitted = 'submitted';
    case Validated = 'validated';
    case Rejected = 'rejected';

    /**
     * Transitions qu'un exécutant peut faire lui-même. La soumission, la validation et
     * le rejet passent par le circuit de validation.
     *
     * @return list<self>
     */
    public function manualTransitions(): array
    {
        return match ($this) {
            self::Todo => [self::InProgress, self::OnHold],
            self::InProgress => [self::OnHold, self::Todo],
            self::OnHold => [self::InProgress],
            self::Rejected => [self::InProgress],
            self::Submitted, self::Validated => [],
        };
    }

    public function canSubmit(): bool
    {
        return in_array($this, [self::Todo, self::InProgress, self::Rejected], true);
    }

    public function isClosed(): bool
    {
        return $this === self::Validated;
    }
}
