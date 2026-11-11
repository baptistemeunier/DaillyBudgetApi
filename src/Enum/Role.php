<?php

namespace App\Enum;

enum Role: string
{
    case ACCOUNT_CONNECTION_READ = 'ROLE_account_connection:read';
    case ACCOUNT_CONNECTION_WRITE = 'ROLE_account_connection:write';
}
