<?php

/*
 * Data retention. These are compliance decisions made in code review, not
 * operator settings, so they are deliberately not read from .env.
 */
return [

    /*
     * Days a soft-deleted record stays recoverable before `model:prune`
     * removes it permanently (and, for accounts, before personal data that
     * survived anonymisation is gone for good). Payments are never pruned.
     */
    'deleted_records_days' => 30,

];
