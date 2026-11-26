<?php

namespace App\Domain\Publishing;

use RuntimeException;

/** A sending problem that is worth another try (for example Instagram taking too long to prepare a video). */
class TemporaryFailure extends RuntimeException {}
