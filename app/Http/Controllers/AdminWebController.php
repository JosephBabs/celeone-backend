<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\User;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class AdminWebController extends Controller
{
    public function dashboard(): View
    {
        $counts=['users'=>User::count(),'posts'=>Post::count(),'firebase_documents'=>DB::table('firebase_documents')->count(),'open_conflicts'=>DB::table('migration_conflicts')->where('status','open')->count(),'pending_outbox'=>DB::table('outbox_events')->whereNull('published_at')->count()];
        return view('admin.dashboard',['users'=>User::latest()->limit(20)->get(),'posts'=>Post::with('user:id,name')->latest()->limit(20)->get(),'counts'=>$counts,'domains'=>config('celeone_domains.collections',[])]);
    }
    public function domain(string $domain): View
    {
        $table=config('celeone_domains.collections.'.$domain,$domain);abort_unless(in_array($table,array_unique(array_values(config('celeone_domains.collections',[]))),true),404);
        return view('admin.domain',['domain'=>$domain,'table'=>$table,'rows'=>DB::table($table)->orderByDesc('id')->paginate(50)]);
    }
}
