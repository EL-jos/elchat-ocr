<?php

namespace App\Models\Social;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

class SocialConversationLink extends Model
{
    use HasUuid;
    protected $guarded = [];
}
