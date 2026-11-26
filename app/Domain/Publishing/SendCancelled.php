<?php

namespace App\Domain\Publishing;

use RuntimeException;

/** The post's approval changed while it was being prepared, so the final send was not made. */
class SendCancelled extends RuntimeException {}
