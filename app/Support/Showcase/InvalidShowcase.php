<?php

namespace App\Support\Showcase;

use RuntimeException;

/**
 * A showcase file that cannot be imported as a whole. The message always names the offending field with its path in
 * the file ("lessons[3].questions[1].type"), so the file can be fixed completely before the next run.
 */
final class InvalidShowcase extends RuntimeException {}
