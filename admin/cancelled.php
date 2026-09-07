<?php
/**
 * FILE PURPOSE: Dedicated Admin view for every cancelled reservation and its cancellation settlement history.
 * DEBUGGING: Listing/filter/payment behavior is shared with reservations.php through the cancelled-only mode flag.
 */
$cancelledReservationsOnly = true;
require __DIR__ . '/reservations.php';
