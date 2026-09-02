<?php
/**
 * What to show a user for an exception.
 *
 * The raw message used to go straight to the phone, so an employee trying to
 * clock in was shown "SQLSTATE[42S22]: Column not found: 1054 Unknown column
 * 'start_photo' in 'field list'". That tells them nothing they can act on, and
 * hands out table and column names to anyone who cares to look. The detail
 * still goes to the error log, which is where it is useful.
 */
function userFacingError(Throwable $e)
{
    $raw = $e->getMessage();

    // A duplicate key on attendance means they already clocked in — usually a
    // second tap after a slow upload, not an error worth alarming them with.
    if (stripos($raw, 'Duplicate entry') !== false) {
        return stripos($raw, 'attendance') !== false
            ? 'That has already been recorded for today.'
            : 'That record already exists.';
    }
    // Checked before the general SQLSTATE case below, which would otherwise
    // swallow it — a foreign key failure also carries an SQLSTATE prefix.
    if (stripos($raw, 'foreign key constraint') !== false) {
        return 'That could not be saved because a linked record is missing.';
    }
    if (stripos($raw, 'SQLSTATE') !== false || stripos($raw, 'Unknown column') !== false) {
        return 'Something went wrong saving that. Please try again, and tell your administrator if it keeps happening.';
    }
    return 'Something went wrong. Please try again.';
}
