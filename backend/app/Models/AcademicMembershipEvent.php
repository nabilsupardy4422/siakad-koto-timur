<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
final class AcademicMembershipEvent extends Model {
    protected $fillable=['siswa_id','source_membership_id','destination_membership_id','actor_id','action','effective_on'];
    protected function casts(): array { return ['effective_on'=>'date']; }
}
