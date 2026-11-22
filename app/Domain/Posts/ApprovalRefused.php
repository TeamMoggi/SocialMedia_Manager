<?php

namespace App\Domain\Posts;

use RuntimeException;

/** An approval or time change that can't be done, with the reason in plain words for the owner. */
final class ApprovalRefused extends RuntimeException {}
