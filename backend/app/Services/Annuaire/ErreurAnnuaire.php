<?php

declare(strict_types=1);

namespace App\Services\Annuaire;

use RuntimeException;

/** L'annuaire n'a pas pu être joint ou a répondu autre chose que ce qu'on attend. */
final class ErreurAnnuaire extends RuntimeException {}
