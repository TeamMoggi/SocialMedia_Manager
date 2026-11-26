<?php

namespace App\Domain\Publishing;

/** What the publisher should do with one network version of a post right now (see TargetPlanner). */
enum Action: string
{
    case Nothing = 'nothing';
    case HandToFacebook = 'hand_to_facebook';
    case PublishFacebookNow = 'publish_facebook_now';
    case PrepareInstagram = 'prepare_instagram';
    case PublishInstagram = 'publish_instagram';
    case RetryNow = 'retry_now';
    /** The on-time window is over: the target becomes Late and waits for the next calendar day. */
    case MarkLate = 'mark_late';
    case CatchUp = 'catch_up';
    case GiveUp = 'give_up';
}
