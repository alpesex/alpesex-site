<?php
declare(strict_types=1);
// Patch one reviewed access rule only; preserve other live application corrections.
[$script,$livePath,$destination]=$argv;
$live=file_get_contents($livePath);
$old=<<<'OLD'
return (int) ($project['team_manager_id'] ?? 0) === $user['id'] ? 'editor' : 'none';
OLD;
$new=<<<'NEW'
require_once __DIR__ . '/../Team/TeamHierarchy.php';
            return (new \AlpesEx\Portal\Team\TeamHierarchy($this->pdo))->managerAccess(
                $user['organizationId'], $user['id'], (int) $project['owner_user_id']
            );
NEW;
if(!is_string($live))exit(1);
if(substr_count($live,$new)===1 && substr_count($live,$old)===0){file_put_contents($destination,$live);exit;}
if(substr_count($live,$old)!==1){fwrite(STDERR,"Règle de droits différente : arrêt sans publication.\n");exit(1);}
file_put_contents($destination,str_replace($old,$new,$live));
