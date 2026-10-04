<?php

return [

    /*
    | Nothing can be booked with an effective date on or before this day
    | (YYYY-MM-DD), once a month's accounts are closed. Empty = nothing closed.
    */
    'books_closed_until' => env('LEDGER_BOOKS_CLOSED_UNTIL'),

    /*
    | Staff balance adjustments above this many naira wait for a second
    | staff member to approve them (money-safety audit I2). Settings →
    | Money can change it.
    */
    'adjustment_approval_over' => (float) env('LEDGER_ADJUSTMENT_APPROVAL_OVER', 5000),

    /*
    | The most one staff adjustment can be, approved or not.
    */
    'adjustment_max' => (float) env('LEDGER_ADJUSTMENT_MAX', 1000000),

];
