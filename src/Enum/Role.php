<?php

namespace App\Enum;

enum Role: string
{
    case ACCOUNT_CONNECTION_READ = 'ROLE_account_connection:read';
    case ACCOUNT_CONNECTION_WRITE = 'ROLE_account_connection:write';
    case ACCOUNT_READ = 'ROLE_account:read';
    case ACCOUNT_WRITE = 'ROLE_account:write';
    case CATEGORY_READ = 'ROLE_category:read';
    case CATEGORY_WRITE = 'ROLE_category:write';
}
