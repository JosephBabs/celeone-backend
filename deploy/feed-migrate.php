<?php
// Run as root. Generate Laravel's migration SQL, then apply it using the local
// PostgreSQL OS account without giving the application role DDL privileges.
require dirname(__DIR__).'/vendor/autoload.php';
$app=require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!function_exists('posix_geteuid') || posix_geteuid()!==0) throw new RuntimeException('Run this deployment helper as root.');
$name='2026_09_18_000300_add_diverse_feed';
if (Illuminate\Support\Facades\DB::table('migrations')->where('migration',$name)->exists()) { echo "Feed migration already applied.\n"; exit; }
$migration=require dirname(__DIR__).'/database/migrations/'.$name.'.php';
$queries=Illuminate\Support\Facades\DB::pretend(fn()=>$migration->up());
$pdo=Illuminate\Support\Facades\DB::connection()->getPdo();
$sql="BEGIN;\n";
foreach ($queries as $query) {
    $statement=$query['query'];
    foreach ($query['bindings'] as $binding) {
        $position=strpos($statement,'?');
        if ($position===false) throw new RuntimeException('Migration binding mismatch');
        $statement=substr_replace($statement,$pdo->quote((string)$binding),$position,1);
    }
    $sql.=$statement.";\n";
}
$role=(string)config('database.connections.pgsql.username');
$quoted='"'.str_replace('"','""',$role).'"';
$sql.='GRANT SELECT, INSERT, UPDATE, DELETE ON feed_cursors, feed_affinities, feed_interactions TO '.$quoted.";\n";
$sql.='GRANT USAGE, SELECT ON SEQUENCE feed_interactions_id_seq TO '.$quoted.";\n";
$sql.="INSERT INTO migrations (migration,batch) SELECT ".$pdo->quote($name).", COALESCE(MAX(batch),0)+1 FROM migrations;\nCOMMIT;\n";
$process=proc_open(['sudo','-u','postgres','psql','-d',(string)config('database.connections.pgsql.database'),'-v','ON_ERROR_STOP=1'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if (!is_resource($process)) throw new RuntimeException('Cannot start PostgreSQL migration process');
fwrite($pipes[0],$sql);fclose($pipes[0]);
echo stream_get_contents($pipes[1]);fclose($pipes[1]);
fwrite(STDERR,stream_get_contents($pipes[2]));fclose($pipes[2]);
$status=proc_close($process);
if ($status!==0) exit($status);
echo "Feed migration applied; application runtime grants restored.\n";
