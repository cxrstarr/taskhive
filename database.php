<?php
// Ensure CSP/security headers are emitted early on most requests.
// (Some hosting setups ignore .htaccess auto_prepend_file; this makes CSP consistent.)
require_once __DIR__ . '/includes/csp.php';
/**
 * TaskHive unified data layer (PDO) with:
 *  - Users / Profiles
 *  - Services
 *  - Conversations / Messages
 *  - Bookings lifecycle
 *  - Payment terms (advance / downpayment / postpaid)
 *  - Escrow + Commission release
 *  - Wallet crediting
 *  - Reviews
 *  - Freelancer receiving payment methods
 *  - Extended recordPayment with payer details & reference code
 *
 * Debugging Aid:
 *   Set DEBUG_DB_PAY to true to log verbose payment debug info.
 */
const DEBUG_DB_PAY = false; // Set true only for local debugging; keep false on production

// Set a consistent application timezone to avoid PHP/MySQL drift in timestamps
// Adjust this to your preferred region if needed
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Manila');
}

class database {

    /* ---------------- CONFIG ---------------- */
    private float $commissionRate = 0.07; // 7% platform commission on release
    private float $defaultDownpaymentPercent = 50.00;

    /* ---------------- CONNECTION ---------------- */
    function opencon(): PDO {
        static $pdo = null;
        if ($pdo === null) {
            try {
                if ($this->shouldUseHostinger()) {
                    // Production (Hostinger)
                    $pdo = new PDO(
                        'mysql:host=mysql.hostinger.com;dbname=u679323211_taskhive;charset=utf8mb4',
                        'u679323211_taskhive',
                        '@Taskhive123',
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                } else {
                    // Local development
                    $pdo = new PDO(
                        'mysql:host=localhost;dbname=taskhive;charset=utf8mb4',
                        'root',
                        '',
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                }
            } catch (Throwable $e) {
                error_log('[DB] Connection failed: ' . $e->getMessage());
                throw $e;
            }
        }
        return $pdo;
    }

    private function shouldUseHostinger(): bool {
        // Use explicit env var if provided
        $env = getenv('TASKHIVE_ENV') ?: '';
        if (strtolower($env) === 'prod') return true;
        if (strtolower($env) === 'dev') return false;
        // Auto-detect by host name
        $host = $_SERVER['HTTP_HOST'] ?? '';
        return stripos($host, 'thetaskhive.site') !== false || stripos($host, 'hostinger') !== false;
    }

    // Hostinger connection (production)
    // Usage: $this->opencon_hostinger() to connect to your Hostinger MySQL instance
    function opencon_hostinger(): PDO {
        static $pdoHost = null;
        if ($pdoHost === null) {
            $pdoHost = new PDO(
                'mysql:host=mysql.hostinger.com;dbname=u679323211_taskhive;charset=utf8mb4',
                'u679323211_taskhive',
                '@Taskhive123',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return $pdoHost;
    }
    function begin(){ $this->opencon()->beginTransaction(); }
    function commit(){ $this->opencon()->commit(); }
    function rollback(){ if ($this->opencon()->inTransaction()) $this->opencon()->rollBack(); }

    private function dbg(string $tag, mixed $data): void {
        if (!DEBUG_DB_PAY) return;
        $msg = '['.$tag.'] '.(is_string($data)?$data:json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        error_log($msg);
    }

    // Optional: quick connectivity test
    function pingDb(): bool {
        try {
            $this->opencon()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            error_log('[DB][PING] '.$e->getMessage());
            return false;
        }
    }

    /* ---------------- USER / AUTH ---------------- */
    function userExists(string $email): bool {
        $st=$this->opencon()->prepare("SELECT 1 FROM users WHERE email=? LIMIT 1");
        $st->execute([$email]);
        return (bool)$st->fetchColumn();
    }
    function registerClient($first,$last,$email,$password,$phone=null,$profile_picture=null): int|false {
        if ($this->userExists($email)) return false;
        $st=$this->opencon()->prepare("INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,profile_picture,created_at)
                                       VALUES (:f,:l,:e,:p,:h,'client',:pic,NOW())");
        $ok=$st->execute([
            ':f'=>$first, ':l'=>$last, ':e'=>$email, ':p'=>$phone,
            ':h'=>password_hash($password,PASSWORD_DEFAULT),
            ':pic'=>$profile_picture
        ]);
        return $ok?(int)$this->opencon()->lastInsertId():false;
    }
    function registerFreelancer($first,$last,$email,$password,$skills,$address,$hourly_rate=null,$phone=null,$profile_picture=null): int|false {
        if ($this->userExists($email)) return false;
        $pdo=$this->opencon();
        try {
            $this->begin();
            $st=$pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,profile_picture,created_at)
                               VALUES (:f,:l,:e,:p,:h,'freelancer',:pic,NOW())");
            $st->execute([
                ':f'=>$first, ':l'=>$last, ':e'=>$email, ':p'=>$phone,
                ':h'=>password_hash($password,PASSWORD_DEFAULT),
                ':pic'=>$profile_picture
            ]);
            $uid=(int)$pdo->lastInsertId();
            $st2=$pdo->prepare("INSERT INTO freelancer_profiles (user_id,skills,address,hourly_rate,created_at)
                                VALUES (:u,:s,:a,:hr,NOW())");
            $st2->execute([
                ':u'=>$uid, ':s'=>$skills, ':a'=>$address, ':hr'=>$hourly_rate
            ]);
            $this->commit();
            return $uid;
        } catch(Throwable $e){
            $this->rollback();
            return false;
        }
    }
    function loginUser(string $email,string $password): array|false {
        $st=$this->opencon()->prepare("SELECT user_id,password_hash,user_type,first_name,last_name,profile_picture
                                       FROM users WHERE email=? LIMIT 1");
        $st->execute([$email]);
        $row=$st->fetch();
        if ($row && password_verify($password,$row['password_hash'])) {
            $this->opencon()->prepare("UPDATE users SET last_login_at=NOW() WHERE user_id=?")->execute([$row['user_id']]);
            return $row;
        }
        return false;
    }
    function getUser(int $user_id): array|false {
        $st=$this->opencon()->prepare("SELECT * FROM users WHERE user_id=?");
        $st->execute([$user_id]);
        return $st->fetch();
    }
    function updateUserProfile(int $user_id,array $fields): bool {
        if (!$fields) return false;
        $allowed=['first_name','last_name','phone','bio','profile_picture','status'];
        $set=[]; $params=[":id"=>$user_id];
        foreach($fields as $k=>$v){
            if(in_array($k,$allowed,true)){
                $set[]="`$k`=:$k";
                $params[":$k"]=$v;
            }
        }
        if(!$set) return false;
        $sql="UPDATE users SET ".implode(',',$set).", updated_at=NOW() WHERE user_id=:id";
        return $this->opencon()->prepare($sql)->execute($params);
    }

    /* ---------------- FREELANCER PROFILE ---------------- */
    function ensureFreelancerProfile(int $user_id): bool {
        $pdo=$this->opencon();
        $q=$pdo->prepare("SELECT freelancer_profile_id FROM freelancer_profiles WHERE user_id=?");
        $q->execute([$user_id]);
        if ($q->fetch()) return true;
        $ins=$pdo->prepare("INSERT INTO freelancer_profiles (user_id,skills,address,hourly_rate,created_at)
                            VALUES (:u,'',NULL,NULL,NOW())");
        return $ins->execute([':u'=>$user_id]);
    }
    function getFreelancerProfile(int $user_id): array|false {
        $sql="SELECT u.user_id,u.first_name,u.last_name,u.email,u.bio,u.profile_picture,
                     fp.freelancer_profile_id,fp.skills,fp.address,fp.hourly_rate
              FROM users u
              LEFT JOIN freelancer_profiles fp ON fp.user_id=u.user_id
              WHERE u.user_id=:uid AND u.user_type='freelancer'
              LIMIT 1";
        $st=$this->opencon()->prepare($sql);
        $st->execute([':uid'=>$user_id]);
        return $st->fetch();
    }

    /* ---------------- CLIENT PROFILE ---------------- */
    function getClientProfile(int $user_id): array|false {
        $sql="SELECT u.user_id,u.first_name,u.last_name,u.email,u.phone,u.bio,u.profile_picture,
                     (SELECT COUNT(*) FROM bookings b WHERE b.client_id=u.user_id) AS total_bookings,
                     (SELECT COUNT(*) FROM bookings b WHERE b.client_id=u.user_id AND b.status IN ('pending','accepted','in_progress')) AS active_bookings,
                     (SELECT COUNT(*) FROM reviews r WHERE r.reviewer_id=u.user_id) AS reviews_written
              FROM users u
              WHERE u.user_id=:uid AND u.user_type='client'
              LIMIT 1";
        $st=$this->opencon()->prepare($sql);
        $st->execute([':uid'=>$user_id]);
        return $st->fetch();
    }

    /* ---------------- PUBLIC PROFILE ---------------- */
    function getPublicProfile(int $user_id): array|false {
        $u=$this->getUser($user_id);
        if (!$u) return false;
        $result=[
            'user_id'=>$u['user_id'],
            'first_name'=>$u['first_name'],
            'last_name'=>$u['last_name'],
            'email'=>$u['email'],
            'user_type'=>$u['user_type'],
            'bio'=>$u['bio'],
            'profile_picture'=>$u['profile_picture'],
            'avg_rating'=>$u['avg_rating'] ?? null,
            'total_reviews'=>$u['total_reviews'] ?? 0,
        ];
        if ($u['user_type']==='freelancer') {
            $fp=$this->getFreelancerProfile($user_id);
            if ($fp) {
                $result['skills']=$fp['skills'];
                $result['hourly_rate']=$fp['hourly_rate'];
                $result['services']=$this->listFreelancerServices($user_id);
            } else {
                $result['skills']='';
                $result['hourly_rate']=null;
                $result['services']=[];
            }
        }
        $result['reviews']=$this->getUserReviews($user_id,50);
        return $result;
    }
    function getUserReviews(int $user_id,int $limit=50): array {
        $sql="SELECT r.*,u.first_name,u.last_name
              FROM reviews r
              JOIN users u ON r.reviewer_id=u.user_id
              WHERE r.reviewee_id=:uid
              ORDER BY r.created_at DESC
              LIMIT :l";
        $st=$this->opencon()->prepare($sql);
        $st->bindValue(':uid',$user_id,PDO::PARAM_INT);
        $st->bindValue(':l',$limit,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /* ---------------- SERVICES ---------------- */
    function slugify(string $text): string {
        $text=strtolower(trim($text));
        $text=preg_replace('/[^a-z0-9]+/','-',$text);
        return trim($text,'-')?:'svc-'.bin2hex(random_bytes(3));
    }
    function serviceSlugExists(string $slug): bool {
        $st=$this->opencon()->prepare("SELECT 1 FROM services WHERE slug=? LIMIT 1");
        $st->execute([$slug]);
        return (bool)$st->fetchColumn();
    }
    function createService(int $freelancer_id, ?int $category_id, string $title,string $description,float $base_price,
                           string $price_unit='fixed',int $min_units=1,int $is_premium=0): int|false {
        $pdo=$this->opencon();
        $slug=$this->slugify($title); $i=1; $orig=$slug;
        while($this->serviceSlugExists($slug)) $slug=$orig.'-'.$i++;
        $st=$pdo->prepare("INSERT INTO services (freelancer_id,category_id,title,slug,description,base_price,price_unit,min_units,is_premium,created_at)
                           VALUES (:f,:c,:t,:s,:d,:bp,:pu,:mu,:ip,NOW())");
        $ok=$st->execute([
            ':f'=>$freelancer_id, ':c'=>$category_id, ':t'=>$title, ':s'=>$slug,
            ':d'=>$description, ':bp'=>$base_price, ':pu'=>$price_unit,
            ':mu'=>$min_units, ':ip'=>$is_premium
        ]);
        return $ok?(int)$pdo->lastInsertId():false;
    }
    function getService(int $service_id): array|false {
        $st=$this->opencon()->prepare("SELECT s.*,u.first_name,u.last_name,u.profile_picture
                                       FROM services s JOIN users u ON s.freelancer_id=u.user_id
                                       WHERE s.service_id=? LIMIT 1");
        $st->execute([$service_id]);
        return $st->fetch();
    }
    function listFreelancerServices(int $freelancer_id): array {
        $st=$this->opencon()->prepare("SELECT * FROM services WHERE freelancer_id=? ORDER BY created_at DESC");
        $st->execute([$freelancer_id]);
        return $st->fetchAll();
    }
    function listAllServices(int $limit=30,int $offset=0,?string $search=null): array {
        $pdo=$this->opencon();
        if ($search!==null && $search!=='') {
            $like='%'.$search.'%';
            $sql="SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.slug,s.created_at,s.category_id,
                         c.name AS category_name,
                         s.avg_rating,s.total_reviews,
                         u.user_id AS freelancer_id,u.first_name,u.last_name,u.profile_picture
                  FROM services s
                  LEFT JOIN service_categories c ON c.category_id = s.category_id
                  JOIN users u ON s.freelancer_id=u.user_id
                  WHERE s.status='active' AND (s.title LIKE :qt OR s.description LIKE :qd)
                  ORDER BY s.created_at DESC
                  LIMIT :o,:l";
            $st=$pdo->prepare($sql);
            $st->bindValue(':qt',$like,PDO::PARAM_STR);
            $st->bindValue(':qd',$like,PDO::PARAM_STR);
        } else {
            $st=$pdo->prepare("SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.slug,s.created_at,s.category_id,
                                      c.name AS category_name,
                                      s.avg_rating,s.total_reviews,
                                      u.user_id AS freelancer_id,u.first_name,u.last_name,u.profile_picture
                               FROM services s
                               LEFT JOIN service_categories c ON c.category_id = s.category_id
                               JOIN users u ON s.freelancer_id=u.user_id
                               WHERE s.status='active'
                               ORDER BY s.created_at DESC
                               LIMIT :o,:l");
        }
        $st->bindValue(':o',$offset,PDO::PARAM_INT);
        $st->bindValue(':l',$limit,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    // Map of category_id => name for service categories
    function listServiceCategoryNames(): array {
        $st = $this->opencon()->query("SELECT category_id,name FROM service_categories");
        $map = [];
        foreach ($st->fetchAll() as $row) {
            $map[(int)$row['category_id']] = $row['name'];
        }
        return $map;
    }
    function countAllServices(?string $search=null): int {
        $pdo=$this->opencon();
        if ($search!==null && $search!=='') {
            $like='%'.$search.'%';
            $st=$pdo->prepare("SELECT COUNT(*) FROM services
                               WHERE status='active' AND (title LIKE :qt OR description LIKE :qd)");
            $st->bindValue(':qt',$like,PDO::PARAM_STR);
            $st->bindValue(':qd',$like,PDO::PARAM_STR);
        } else {
            $st=$pdo->prepare("SELECT COUNT(*) FROM services WHERE status='active'");
        }
        $st->execute();
        return (int)$st->fetchColumn();
    }

    /* ---------------- CONVERSATIONS ---------------- */

    
    /* ---------------- CONVERSATIONS ---------------- */

    // Unified 1:1 chat: always return the general conversation between the pair
    function createConversation(int $client_id,int $freelancer_id,?int $booking_id=null): int|false {
        return $this->createOrGetGeneralConversation($client_id,$freelancer_id);
    }

    function createOrGetGeneralConversation(int $userA,int $userB): int|false {
        if ($userA === $userB) return false;
        $ua=$this->getUser($userA);
        $ub=$this->getUser($userB);
        if (!$ua || !$ub) return false;

        $typeA = (string)($ua['user_type'] ?? '');
        $typeB = (string)($ub['user_type'] ?? '');

        // Primary: client-freelancer mapping (original behavior)
        if ($typeA === 'client' && $typeB === 'freelancer') {
            $cid = (int)$ua['user_id'];
            $fid = (int)$ub['user_id'];
        } elseif ($typeA === 'freelancer' && $typeB === 'client') {
            $cid = (int)$ub['user_id'];
            $fid = (int)$ua['user_id'];
        } elseif ($typeA === 'freelancer' && $typeB === 'freelancer') {
            // New: allow freelancer-to-freelancer direct messaging by mapping the pair to (client_id, freelancer_id)
            // in a stable order to ensure uniqueness independent of caller order.
            $a = (int)$ua['user_id'];
            $b = (int)$ub['user_id'];
            $cid = min($a,$b);
            $fid = max($a,$b);
        } else {
            // client-client not supported at the moment
            return false;
        }

        $pdo=$this->opencon();
        // 1) Reuse ANY existing conversation for this pair (regardless of type)
        $sel=$pdo->prepare("SELECT conversation_id FROM conversations
                            WHERE client_id=? AND freelancer_id=?
                            ORDER BY last_message_at DESC, created_at DESC, conversation_id DESC
                            LIMIT 1");
        $sel->execute([$cid,$fid]);
        if ($row=$sel->fetch()) return (int)$row['conversation_id'];

        // 2) Best-effort: enforce uniqueness at DB level to avoid races
        $this->ensureConversationPairUniqueIndex();

        // 3) Create a single canonical 'general' conversation for the pair
        try {
            $ins=$pdo->prepare("INSERT INTO conversations (conversation_type,client_id,freelancer_id,created_at)
                                VALUES ('general',:c,:f,NOW())");
            $ins->execute([':c'=>$cid,':f'=>$fid]);
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            // If a concurrent request inserted it, select again
            try {
                $sel=$pdo->prepare("SELECT conversation_id FROM conversations
                                    WHERE client_id=? AND freelancer_id=?
                                    ORDER BY last_message_at DESC, created_at DESC, conversation_id DESC
                                    LIMIT 1");
                $sel->execute([$cid,$fid]);
                if ($row=$sel->fetch()) return (int)$row['conversation_id'];
            } catch (Throwable $e2) {}
            return false;
        }
    }

    // Ensure a unique index exists for (client_id,freelancer_id) to enforce one conversation per pair
    private function ensureConversationPairUniqueIndex(): void {
        try {
            $pdo = $this->opencon();
            // Create a unique index if it doesn't exist yet; ignore errors if it already exists
            $pdo->query("CREATE UNIQUE INDEX IF NOT EXISTS uniq_conversations_pair ON conversations (client_id, freelancer_id)");
        } catch (Throwable $e) {
            // Some MySQL/MariaDB versions don't support IF NOT EXISTS on indexes; fallback: attempt to detect
            try {
                $pdo = $this->opencon();
                $chk = $pdo->prepare("SHOW INDEX FROM conversations WHERE Key_name='uniq_conversations_pair'");
                $chk->execute();
                if (!$chk->fetch()) {
                    // Try to add the index (may throw if duplicate rows exist)
                    $pdo->query("ALTER TABLE conversations ADD UNIQUE KEY uniq_conversations_pair (client_id, freelancer_id)");
                }
            } catch (Throwable $e2) {
                // Swallow; duplicate prevention will rely on SELECT-before-INSERT path
            }
        }
    }
    
    function addMessage(int $conversation_id,int $sender_id,string $body,string $type='text',?int $booking_id=null,$attachments=null): int|false {
        $pdo = $this->opencon();
        // Use PHP-side timestamp to stay consistent with application timezone
        $createdAt = date('Y-m-d H:i:s');

        $st = $pdo->prepare("INSERT INTO messages (conversation_id,sender_id,booking_id,body,message_type,attachments,created_at)
                              VALUES (:c,:s,:b,:body,:mt,:att,:created)");
        $st->execute([
            ':c'      => $conversation_id,
            ':s'      => $sender_id,
            ':b'      => $booking_id,
            ':body'   => $body,
            ':mt'     => $type,
            ':att'    => $attachments ? json_encode($attachments) : null,
            ':created'=> $createdAt,
        ]);
        $mid = (int)$pdo->lastInsertId();

        // Keep conversation "last" fields in sync with the same timestamp to avoid drift
        $pdo->prepare("UPDATE conversations SET last_message_id=:m, last_message_at=:created WHERE conversation_id=:c")
            ->execute([':m' => $mid, ':created' => $createdAt, ':c' => $conversation_id]);

        // If any participant hid this conversation, automatically unhide it on new incoming messages
        try {
            $p = $pdo->prepare("SELECT client_id, freelancer_id FROM conversations WHERE conversation_id=:c LIMIT 1");
            $p->execute([':c'=>$conversation_id]);
            if ($row = $p->fetch()) {
                $clientId = (int)$row['client_id'];
                $freeId   = (int)$row['freelancer_id'];
                // Unhide for both participants to ensure visibility resumes
                $this->unhideConversationForUser($conversation_id, $clientId);
                $this->unhideConversationForUser($conversation_id, $freeId);
            }
        } catch (Throwable $e) {
            // best-effort; ignore failures
        }
        return $mid;
    }
    function getConversationMessages(int $conversation_id,int $limit=50,int $offset=0): array {
        $st=$this->opencon()->prepare("SELECT m.*,u.first_name,u.last_name,u.profile_picture
                                       FROM messages m JOIN users u ON m.sender_id=u.user_id
                                       WHERE conversation_id=:c
                                       ORDER BY message_id DESC
                                       LIMIT :o,:l");
        $st->bindValue(':c',$conversation_id,PDO::PARAM_INT);
        $st->bindValue(':o',$offset,PDO::PARAM_INT);
        $st->bindValue(':l',$limit,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    // Fetch messages older than a specific message_id (for lazy-load pagination)
    function getConversationMessagesBeforeId(int $conversation_id, int $before_message_id, int $limit=50): array {
        $limit = max(1, min(200, (int)$limit));
        $st = $this->opencon()->prepare("SELECT m.*, u.first_name, u.last_name, u.profile_picture
                                          FROM messages m
                                          JOIN users u ON m.sender_id = u.user_id
                                          WHERE m.conversation_id = :c AND m.message_id < :mid
                                          ORDER BY m.message_id DESC
                                          LIMIT :l");
        $st->bindValue(':c', $conversation_id, PDO::PARAM_INT);
        $st->bindValue(':mid', $before_message_id, PDO::PARAM_INT);
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
    function listConversationsWithUnread(int $user_id,int $limit=100,int $offset=0): array {
        $limit=max(1,(int)$limit);
        $offset=max(0,(int)$offset);
                // Ensure optional visibility table exists (no-op if already there)
                $this->ensureConversationUserStateTable();

                $sql="
                    SELECT
                        c.conversation_id,
                        c.conversation_type,
                        c.last_message_at,
                        c.booking_id,
                        c.client_id,
                        c.freelancer_id,
                        cl.first_name AS client_first,
                        cl.last_name  AS client_last,
                        cl.profile_picture AS client_pic,
                        fr.first_name AS free_first,
                        fr.last_name  AS free_last,
                        fr.profile_picture AS free_pic,
                        (SELECT m2.body
                         FROM messages m2
                         WHERE m2.conversation_id=c.conversation_id
                         ORDER BY m2.message_id DESC
                         LIMIT 1) AS last_body,
                        (SELECT COUNT(*)
                         FROM messages m3
                         WHERE m3.conversation_id=c.conversation_id
                             AND m3.sender_id <> :sub_sender
                             AND m3.read_at IS NULL) AS unread_count
                    FROM conversations c
                    JOIN users cl ON c.client_id=cl.user_id
                    JOIN users fr ON c.freelancer_id=fr.user_id
                    LEFT JOIN conversation_user_state cus
                                 ON cus.conversation_id = c.conversation_id
                                AND cus.user_id = :visibility_user
                    WHERE (c.client_id=:outer_client OR c.freelancer_id=:outer_freelancer)
                        AND (cus.hidden_at IS NULL)
                    ORDER BY (c.last_message_at IS NULL) ASC,
                                     c.last_message_at DESC,
                                     c.created_at DESC
                    LIMIT $offset,$limit";
        $st=$this->opencon()->prepare($sql);
        $st->bindValue(':sub_sender',$user_id,PDO::PARAM_INT);
        $st->bindValue(':outer_client',$user_id,PDO::PARAM_INT);
        $st->bindValue(':outer_freelancer',$user_id,PDO::PARAM_INT);
                $st->bindValue(':visibility_user',$user_id,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    function getLatestBookingBetweenUsers(int $client_id,int $freelancer_id, ?array $statuses=null): array|false {
        $pdo = $this->opencon();
        $where = "client_id=:c AND freelancer_id=:f";
        $params = [':c'=>$client_id, ':f'=>$freelancer_id];

        if ($statuses && count($statuses)>0) {
            // Build IN list with named placeholders to avoid mixing positional + named
            $ph = [];
            foreach (array_values($statuses) as $i => $s) {
                $key = ":s{$i}";
                $ph[] = $key;
                $params[$key] = $s;
            }
            $in = implode(',', $ph);
            $sql = "SELECT booking_id FROM bookings WHERE $where AND status IN ($in) ORDER BY created_at DESC LIMIT 1";
            $st  = $pdo->prepare($sql);
            $st->execute($params);
        } else {
            $st = $pdo->prepare("SELECT booking_id FROM bookings WHERE $where ORDER BY created_at DESC LIMIT 1");
            $st->execute($params);
        }

        if ($row = $st->fetch()) {
            return $this->fetchBookingWithContext((int)$row['booking_id']);
        }
        return false;
    }

    function countUnreadMessages(int $user_id): int {
        // Exclude conversations the current user has hidden
        // Use distinct placeholders to avoid HY093 when emulate prepares is disabled
        $this->ensureConversationUserStateTable();
        $sql="SELECT COUNT(*)
              FROM messages m
              INNER JOIN conversations c ON m.conversation_id=c.conversation_id
              LEFT JOIN conversation_user_state cus
                     ON cus.conversation_id = c.conversation_id
                    AND cus.user_id = :u1
              WHERE m.read_at IS NULL
                AND m.sender_id <> :u2
                AND (c.client_id = :u3 OR c.freelancer_id = :u4)
                AND (cus.hidden_at IS NULL)";
        $st=$this->opencon()->prepare($sql);
        $st->bindValue(':u1',$user_id,PDO::PARAM_INT);
        $st->bindValue(':u2',$user_id,PDO::PARAM_INT);
        $st->bindValue(':u3',$user_id,PDO::PARAM_INT);
        $st->bindValue(':u4',$user_id,PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn();
    }
    function markConversationMessagesRead(int $conversation_id,int $user_id): bool {
        $sql="UPDATE messages
              SET read_at=NOW()
              WHERE conversation_id=:c
                AND sender_id<>:u
                AND read_at IS NULL";
        return $this->opencon()->prepare($sql)->execute([':c'=>$conversation_id,':u'=>$user_id]);
    }

    // Update service status: active | paused | archived
    function updateServiceStatus(int $service_id, int $freelancer_id, string $status): bool|string {
        $allowed = ['active','paused','archived'];
        if (!in_array($status, $allowed, true)) {
            return "Invalid status.";
        }
        $pdo = $this->opencon();

        // Ensure the service belongs to the freelancer
        $chk = $pdo->prepare("SELECT service_id,status FROM services WHERE service_id=:sid AND freelancer_id=:uid LIMIT 1");
        $chk->execute([':sid'=>$service_id, ':uid'=>$freelancer_id]);
        $row = $chk->fetch();
        if (!$row) {
            return "Service not found or not yours.";
        }
        $current = strtolower((string)($row['status'] ?? 'active'));
        // Enforce admin approval: cannot self-activate when in draft
        if ($current === 'draft' && $status === 'active') {
            return "Awaiting admin approval.";
        }

        $st = $pdo->prepare("UPDATE services SET status=:s, updated_at=NOW() WHERE service_id=:sid LIMIT 1");
        $ok = $st->execute([':s'=>$status, ':sid'=>$service_id]);

        return $ok ? true : "Could not update service status.";
    }

    // Permanently delete a service if it has no bookings
    function hardDeleteService(int $service_id, int $freelancer_id): bool|string {
        $pdo = $this->opencon();

        // Ensure ownership
        $chk = $pdo->prepare("SELECT service_id FROM services WHERE service_id=:sid AND freelancer_id=:uid LIMIT 1");
        $chk->execute([':sid'=>$service_id, ':uid'=>$freelancer_id]);
        if (!$chk->fetch()) {
            return "Service not found or not yours.";
        }

        // Block delete if there are bookings pointing to this service
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE service_id=:sid");
        $cnt->execute([':sid'=>$service_id]);
        if ((int)$cnt->fetchColumn() > 0) {
            return "Service has bookings. Archive it instead.";
        }

        try {
            $this->begin();

            // Clean up related media (will be cascaded, but doing it explicitly is safe)
            $pdo->prepare("DELETE FROM service_media WHERE service_id=:sid")->execute([':sid'=>$service_id]);

            // Delete the service
            $del = $pdo->prepare("DELETE FROM services WHERE service_id=:sid LIMIT 1");
            $del->execute([':sid'=>$service_id]);

            $this->commit();
            return true;
        } catch (Throwable $e) {
            $this->rollback();
            return "Delete failed: ".$e->getMessage();
        }
    }

    /* ---------------- BOOKINGS ---------------- */
    function getServiceBySlug(string $slug): array|false {
        $st=$this->opencon()->prepare("
            SELECT s.*, u.user_id AS freelancer_user_id, u.first_name, u.last_name
            FROM services s
            JOIN users u ON s.freelancer_id = u.user_id
            WHERE s.slug = ? AND s.status='active' LIMIT 1
        ");
        $st->execute([$slug]);
        return $st->fetch();
    }
    function fetchBookingWithContext(int $booking_id): array|false {
        $sql="SELECT b.*, s.title AS service_title,
                     CONCAT(c.first_name,' ',c.last_name) AS client_name,
                     CONCAT(f.first_name,' ',f.last_name) AS freelancer_name
              FROM bookings b
              JOIN services s ON b.service_id=s.service_id
              JOIN users c ON b.client_id=c.user_id
              JOIN users f ON b.freelancer_id=f.user_id
              WHERE b.booking_id=:id
              LIMIT 1";
        $st=$this->opencon()->prepare($sql);
        $st->execute([':id'=>$booking_id]);
        return $st->fetch();
    }
    function getBookingForClient(int $booking_id,int $client_id): array|false {
        $st=$this->opencon()->prepare("
            SELECT b.*, s.title AS service_title, CONCAT(f.first_name,' ',f.last_name) AS freelancer_name
            FROM bookings b
            JOIN services s ON b.service_id=s.service_id
            JOIN users f ON b.freelancer_id=f.user_id
            WHERE b.booking_id=:bid AND b.client_id=:cid
            LIMIT 1
        ");
        $st->execute([':bid'=>$booking_id, ':cid'=>$client_id]);
        return $st->fetch();
    }
    function getBookingForFreelancer(int $booking_id,int $freelancer_id): array|false {
        $st=$this->opencon()->prepare("
            SELECT b.*, s.title AS service_title, CONCAT(c.first_name,' ',c.last_name) AS client_name
            FROM bookings b
            JOIN services s ON b.service_id=s.service_id
            JOIN users c ON b.client_id=c.user_id
            WHERE b.booking_id=:bid AND b.freelancer_id=:fid
            LIMIT 1
        ");
        $st->execute([':bid'=>$booking_id, ':fid'=>$freelancer_id]);
        return $st->fetch();
    }
    function listFreelancerPendingBookings(int $freelancer_id,int $limit=30): array {
        $st=$this->opencon()->prepare("
            SELECT b.booking_id,b.service_id,b.quantity,b.total_amount,b.status,b.created_at,
                   s.title AS service_title,
                   CONCAT(c.first_name,' ',c.last_name) AS client_name
            FROM bookings b
            JOIN services s ON b.service_id=s.service_id
            JOIN users c ON b.client_id=c.user_id
            WHERE b.freelancer_id=:fid AND b.status='pending'
            ORDER BY b.created_at DESC
            LIMIT :lim
        ");
        $st->bindValue(':fid',$freelancer_id,PDO::PARAM_INT);
        $st->bindValue(':lim',$limit,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
    function listUserBookings(int $user_id,string $role='client'): array {
        $sql = ($role==='freelancer')
            ? "SELECT * FROM bookings WHERE freelancer_id=:id ORDER BY created_at DESC"
            : "SELECT * FROM bookings WHERE client_id=:id ORDER BY created_at DESC";
        $st=$this->opencon()->prepare($sql);
        $st->execute([':id'=>$user_id]);
        return $st->fetchAll();
    }
    function listClientBookings(int $client_id,int $limit=200,int $offset=0): array {
        $st=$this->opencon()->prepare("
            SELECT b.booking_id,b.service_id,b.quantity,b.total_amount,b.status,
                   b.created_at,b.freelancer_id,b.client_id,
                   s.title AS service_title,
                   CONCAT(f.first_name,' ',f.last_name) AS freelancer_name,
                   f.profile_picture AS freelancer_picture
            FROM bookings b
            JOIN services s ON b.service_id=s.service_id
            JOIN users f ON b.freelancer_id=f.user_id
            WHERE b.client_id=:cid
            ORDER BY b.created_at DESC
            LIMIT :off,:lim
        ");
        $st->bindValue(':cid',$client_id,PDO::PARAM_INT);
        $st->bindValue(':off',max(0,$offset),PDO::PARAM_INT);
        $st->bindValue(':lim',max(1,$limit),PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
    function listClientWrittenReviews(int $client_id,int $limit=30,int $offset=0): array {
        $st=$this->opencon()->prepare("
            SELECT r.review_id,r.booking_id,r.rating,r.comment,r.created_at,
                   CONCAT(u.first_name,' ',u.last_name) AS reviewee_name,
                   u.profile_picture AS reviewee_picture
            FROM reviews r
            JOIN users u ON r.reviewee_id=u.user_id
            WHERE r.reviewer_id=:cid
            ORDER BY r.created_at DESC
            LIMIT :off,:lim
        ");
        $st->bindValue(':cid',$client_id,PDO::PARAM_INT);
        $st->bindValue(':off',max(0,$offset),PDO::PARAM_INT);
        $st->bindValue(':lim',max(1,$limit),PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }
    function updateBookingStatus(int $booking_id,string $status): bool {
        $allowed=['pending','accepted','rejected','in_progress','delivered','completed','cancelled','disputed','refunded'];
        if(!in_array($status,$allowed,true)) return false;
        $col=match($status){
            'accepted'=>'accepted_at',
            'delivered'=>'delivered_at',
            'completed'=>'completed_at',
            'cancelled'=>'cancelled_at',
            default=>null
        };
        $sql="UPDATE bookings SET status=:s".($col?",$col=NOW()":"").", updated_at=NOW() WHERE booking_id=:id";
        return $this->opencon()->prepare($sql)->execute([':s'=>$status,':id'=>$booking_id]);
    }
    function createBooking(int $service_id,int $client_id,int $quantity=1,?string $ss=null,?string $se=null): int|false {
        $pdo=$this->opencon();
        try {
            $this->begin();
            $svc=$this->getService($service_id);
            if(!$svc) throw new Exception("Service missing");
            $freelancer_id=(int)$svc['freelancer_id'];
            if ($freelancer_id===$client_id) throw new Exception("Cannot self-book.");
            if ($quantity<1) throw new Exception("Quantity invalid");

            $unit=(float)$svc['base_price'];
            $subtotal=$unit*$quantity;
            $platform_fee=0.00;
            $total=$subtotal + $platform_fee;

            $ins=$pdo->prepare("INSERT INTO bookings
                (service_id,client_id,freelancer_id,title_snapshot,description_snapshot,
                 unit_price,quantity,platform_fee,total_amount,scheduled_start,scheduled_end,
                 status,payment_status,currency,payment_method,payment_terms_status,downpayment_percent,
                 paid_upfront_amount,total_paid_amount,escrow_status,created_at)
                VALUES (:sid,:cid,:fid,:t,:d,:u,:q,:pf,:tot,:ss,:se,'pending','unpaid','PHP',NULL,'none',:dp,0,0,'none',NOW())");
            $ins->execute([
                ':sid'=>$service_id, ':cid'=>$client_id, ':fid'=>$freelancer_id,
                ':t'=>$svc['title'], ':d'=>$svc['description'],
                ':u'=>$unit, ':q'=>$quantity, ':pf'=>$platform_fee, ':tot'=>$total,
                ':ss'=>$ss, ':se'=>$se, ':dp'=>$this->defaultDownpaymentPercent
            ]);
            $bid=(int)$pdo->lastInsertId();

            // Ensure a single general conversation exists between the pair
            $this->createOrGetGeneralConversation($client_id,$freelancer_id);

            $this->commit();
            return $bid;
        } catch(Throwable $e){
            $this->rollback();
            error_log('[createBooking] '.$e->getMessage());
            return false;
        }
    }

    /* ---------------- PAYMENT TERMS / ESCROW ---------------- */
    function proposePaymentTerms(int $booking_id, int $freelancer_id, string $method): bool|string {
        if (!in_array($method,['advance','downpayment','postpaid'],true)) return "Invalid method.";
        $b = $this->fetchBookingWithContext($booking_id);
        if(!$b) return "Booking not found.";
        if ((int)$b['freelancer_id'] !== $freelancer_id) return "Not your booking.";
        if ($b['payment_terms_status'] !== 'none' && $b['payment_terms_status'] !== 'rejected')
            return "Terms already proposed or accepted.";
        $sql = "UPDATE bookings
                SET payment_method=:m, payment_terms_status='proposed'
                WHERE booking_id=:id";
        $ok = $this->opencon()->prepare($sql)->execute([':m'=>$method, ':id'=>$booking_id]);
        if ($ok) $this->systemBookingMessage($booking_id, $freelancer_id,
            "Freelancer proposed payment method: $method");
        return $ok ? true : "Failed to propose terms.";
    }
    function acceptPaymentTerms(int $booking_id, int $client_id): bool|string {
        $b = $this->fetchBookingWithContext($booking_id);
        if(!$b) return "Booking not found.";
        if ((int)$b['client_id'] !== $client_id) return "Not your booking.";
        if ($b['payment_terms_status'] !== 'proposed') return "No pending proposal.";
        $ok = $this->opencon()->prepare(
              "UPDATE bookings SET payment_terms_status='accepted' WHERE booking_id=:id"
        )->execute([':id'=>$booking_id]);
        if ($ok) $this->systemBookingMessage($booking_id, $client_id,
            "Client accepted payment terms (".$b['payment_method'].").");
        return $ok ? true : "Failed to accept terms.";
    }
    function rejectPaymentTerms(int $booking_id, int $client_id): bool|string {
        $b = $this->fetchBookingWithContext($booking_id);
        if(!$b) return "Booking not found.";
        if ((int)$b['client_id'] !== $client_id) return "Not your booking.";
        if ($b['payment_terms_status'] !== 'proposed') return "No pending proposal.";
        $ok = $this->opencon()->prepare(
              "UPDATE bookings SET payment_terms_status='rejected', payment_method=NULL WHERE booking_id=:id"
        )->execute([':id'=>$booking_id]);
        if ($ok) $this->systemBookingMessage($booking_id, $client_id,
            "Client rejected proposed payment terms.");
        return $ok ? true : "Failed to reject terms.";
    }

 function recordPayment(
    int $booking_id,
    int $payer_id,
    float $amount,
    string $phase,
    string $method,
    ?array $payerDetails = null,
    ?string $referenceCode = null,
    bool $otpVerified = true,
    ?int $receiverMethodId = null
): bool|string {

    // Only these two channels now
    $validPhase  = ['full_advance','downpayment','balance','postpaid_full'];
    $validMethod = ['gcash','paymaya'];

    if (!in_array($phase,$validPhase,true))  return "Invalid payment phase.";
    if (!in_array($method,$validMethod,true)) return "Invalid method.";

    $b = $this->fetchBookingWithContext($booking_id);
    if (!$b) return "Booking not found.";
    if ((int)$b['client_id'] !== $payer_id) return "Only client pays.";
    if ($b['payment_terms_status'] !== 'accepted') return "Payment terms not accepted.";

    // Validate receiver method (if provided) belongs to the freelancer and matches channel
    if ($receiverMethodId) {
        $row = $this->getFreelancerPaymentMethod($receiverMethodId, (int)$b['freelancer_id']);
        if (!$row) return "Receiving method not found for this freelancer.";
        $map = ['gcash'=>'gcash','paymaya'=>'paymaya'];
        $expectedChannel = $map[$row['method_type']] ?? null;
        if ($expectedChannel !== $method) {
            return "Selected receiving method does not match chosen channel.";
        }
    }

    // Allow partial payments: any amount > 0 up to remaining total
    $totalAmount = (float)$b['total_amount'];
    $totalPaid   = (float)$b['total_paid_amount'];
    $remaining   = round(max(0.0, $totalAmount - $totalPaid), 2);
    if ($amount <= 0 || $remaining <= 0) {
        return "Nothing to pay.";
    }
    if ($amount - $remaining > 0.01) {
        return "Amount exceeds remaining balance (₱".number_format($remaining,2).").";
    }

    $pdo = $this->opencon();

    // Detect if payments.receiver_method_id exists; build SQL accordingly
    static $hasRmid = null;
    if ($hasRmid === null) {
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM payments LIKE 'receiver_method_id'");
            $hasRmid = (bool)$chk->fetch();
        } catch (Throwable $e) {
            $hasRmid = false;
        }
    }

    $cols = "(booking_id, amount, currency, status, payment_phase, method, payer_details, reference_code, paid_at, otp_verified_at, created_at";
    $vals = "(:b, :a, 'PHP', 'escrowed', :pp, :m, :pd, :rc, :paid_at, :otp_at, :created_at";
    if ($hasRmid) {
        $cols .= ", receiver_method_id";
        $vals .= ", :rmid";
    }
    $cols .= ")";
    $vals .= ")";

    $sql = "INSERT INTO payments {$cols} VALUES {$vals}";

    $bindings = [
        ':b'         => $booking_id,
        ':a'         => $amount,
        ':pp'        => $phase,
        ':m'         => $method,
        ':pd'        => $payerDetails ? json_encode($payerDetails) : null,
        ':rc'        => $referenceCode,
        ':paid_at'   => date('Y-m-d H:i:s'),
        ':otp_at'    => $otpVerified ? date('Y-m-d H:i:s') : null,
        ':created_at'=> date('Y-m-d H:i:s'),
    ];
    if ($hasRmid) {
        $bindings[':rmid'] = $receiverMethodId; // may be null; OK
    }

    if (defined('DEBUG_DB_PAY') && DEBUG_DB_PAY) {
        preg_match_all('/:([a-zA-Z_][a-zA-Z0-9_]*)/',$sql,$mAll);
        $placeholders = array_values(array_unique($mAll[0]));
        $bindingKeys  = array_keys($bindings);
        $missing = array_values(array_diff($placeholders, $bindingKeys));
        $extra   = array_values(array_diff($bindingKeys, $placeholders));
        error_log('[recordPayment][SQL] '.$sql);
        error_log('[recordPayment][PH] '.json_encode($placeholders));
        error_log('[recordPayment][BIND] '.json_encode($bindingKeys));
        if ($missing) error_log('[recordPayment][MISSING] '.json_encode($missing));
        if ($extra)   error_log('[recordPayment][EXTRA] '.json_encode($extra));
    }

    try {
        $this->begin();

        // 1) Insert payment
        $pst = $pdo->prepare($sql);
        $pst->execute($bindings);

        // 2) Update booking financials allowing partial payments
        if (in_array($phase,['full_advance','downpayment'],true)) {
            $sqlUp = "UPDATE bookings
                      SET paid_upfront_amount = paid_upfront_amount + :amt1,
                          total_paid_amount   = total_paid_amount + :amt2,
                          escrow_status = CASE
                              WHEN (total_paid_amount + :amt3) >= total_amount THEN 'holding'
                              ELSE 'partial'
                          END,
                          payment_status = CASE
                              WHEN (total_paid_amount + :amt4) >= total_amount THEN 'escrowed'
                              ELSE 'partial'
                          END,
                          updated_at=NOW()
                      WHERE booking_id=:bid";
            $upd = $pdo->prepare($sqlUp);
            $upd->execute([
                ':amt1'=>$amount,
                ':amt2'=>$amount,
                ':amt3'=>$amount,
                ':amt4'=>$amount,
                ':bid'=>$booking_id
            ]);
        } else {
            $sqlUp = "UPDATE bookings
                      SET total_paid_amount = total_paid_amount + :amt1,
                          escrow_status = CASE
                              WHEN (total_paid_amount + :amt2) >= total_amount THEN 'holding'
                              ELSE 'partial'
                          END,
                          payment_status = CASE
                              WHEN (total_paid_amount + :amt3) >= total_amount THEN 'escrowed'
                              ELSE 'partial'
                          END,
                          updated_at=NOW()
                      WHERE booking_id=:bid";
            $upd = $pdo->prepare($sqlUp);
            $upd->execute([
                ':amt1'=>$amount,
                ':amt2'=>$amount,
                ':amt3'=>$amount,
                ':bid'=>$booking_id
            ]);
        }

        // 3) System message
        $this->systemBookingMessage(
            $booking_id,
            $payer_id,
            "Client paid ₱".number_format($amount,2)." (phase=$phase, method=$method)."
        );

        // 3.1) Notification to the freelancer about the payment
        try {
            if (method_exists($this,'addNotification')) {
                $this->addNotification((int)$b['freelancer_id'], 'payment_recorded', [
                    'booking_id' => $booking_id,
                    'amount' => $amount,
                    'phase' => $phase,
                    'method' => $method
                ]);
            }
        } catch (Throwable $e) {}

        $this->commit();
        return true;
    } catch (Throwable $e) {
        $this->rollback();
        if (defined('DEBUG_DB_PAY') && DEBUG_DB_PAY) {
            error_log('[recordPayment][EXCEPTION] '.$e->getMessage());
        }
        return "Payment failed: ".$e->getMessage();
    }
}

   function performBookingAction(int $booking_id,int $actor_id,string $actor_role,string $action): bool|string {
        $b=$this->fetchBookingWithContext($booking_id);
        if(!$b) return "Booking not found.";
        $status=$b['status'];

        if ($actor_role==='client' && (int)$b['client_id']!==$actor_id) return "Not your booking.";
        if ($actor_role==='freelancer' && (int)$b['freelancer_id']!==$actor_id) return "Not your booking.";

        $allowed=false; $newStatus=null;

        switch($action){
            case 'accept':
                if($actor_role==='freelancer' && $status==='pending'){ $newStatus='accepted'; $allowed=true; }
                break;
            case 'reject':
                if($actor_role==='freelancer' && $status==='pending'){ $newStatus='rejected'; $allowed=true; }
                break;
            case 'start':
                if($actor_role==='freelancer' && $status==='accepted'){
                    if ($b['payment_method']==='advance' && (float)$b['paid_upfront_amount'] + 0.001 < (float)$b['total_amount'])
                        return "Advance payment required before starting.";
                    if ($b['payment_method']==='downpayment'){
                        $req = round((float)$b['total_amount'] * ((float)$b['downpayment_percent']/100),2);
                        if ((float)$b['paid_upfront_amount'] + 0.001 < $req)
                            return "Downpayment required before starting.";
                    }
                    $newStatus='in_progress'; $allowed=true;
                }
                break;
            case 'deliver':
                if($actor_role==='freelancer' && $status==='in_progress'){ $newStatus='delivered'; $allowed=true; }
                break;
            case 'approve_delivery':
                if($actor_role==='client' && $status==='delivered'){ $newStatus='completed'; $allowed=true; }
                break;
            case 'complete':
                if($actor_role==='freelancer' && $status==='delivered'){ $newStatus='completed'; $allowed=true; }
                break;
            case 'cancel':
                if($actor_role==='client' && in_array($status,['pending'],true)){ $newStatus='cancelled'; $allowed=true; }
                break;
            default:
                return "Unknown action.";
        }
        if(!$allowed || !$newStatus) return "Action not allowed in current status.";

        if(!$this->updateBookingStatus($booking_id,$newStatus)) return "Failed to update status.";

        try {
            if (method_exists($this,'addNotification')) {
                $notifyTarget = ($actor_role==='freelancer') ? (int)$b['client_id'] : (int)$b['freelancer_id'];
                $this->addNotification($notifyTarget,'booking_status_changed',[
                    'booking_id'=>$booking_id,
                    'status'=>$newStatus
                ]);
            }

            // Post a friendly system message to the general conversation between the pair
            $convId = $this->createOrGetGeneralConversation((int)$b['client_id'], (int)$b['freelancer_id']);
            if ($convId) {
                $serviceTitle = $b['service_title'] ?? ($b['title_snapshot'] ?? 'service');
                $body = '';
                if ($actor_role === 'freelancer') {
                    $body = match ($action) {
                        'accept'  => "Freelancer accepted your booking for \"{$serviceTitle}\" (Booking #{$booking_id}).",
                        'reject'  => "Freelancer declined your booking for \"{$serviceTitle}\" (Booking #{$booking_id}).",
                        'start'   => "Freelancer started working on \"{$serviceTitle}\" (Booking #{$booking_id}).",
                        'deliver' => "Freelancer marked \"{$serviceTitle}\" as delivered for your review (Booking #{$booking_id}).",
                        'complete'=> "Freelancer completed \"{$serviceTitle}\" (Booking #{$booking_id}).",
                        default   => "Freelancer updated your booking (Booking #{$booking_id}) — status: {$newStatus}.",
                    };
                } else {
                    // Client-initiated actions also get a clear message to freelancer
                    $body = match ($action) {
                        'approve_delivery' => "Client approved the delivery for \"{$serviceTitle}\" (Booking #{$booking_id}).",
                        'cancel'           => "Client canceled the booking for \"{$serviceTitle}\" (Booking #{$booking_id}).",
                        default            => "Client updated the booking (Booking #{$booking_id}) — status: {$newStatus}.",
                    };
                }
                $this->addMessage($convId,$actor_id,$body,'system',$booking_id);
            }
        } catch(Throwable $e){}

        if ($newStatus==='completed') {
            // Post a friendly system message to prompt the client to leave a review
            try {
                $serviceTitle = $b['service_title'] ?? ($b['title_snapshot'] ?? 'service');
                $freelancerName = $b['freelancer_name'] ?? 'the freelancer';
                $url = 'leave_review.php?booking_id='.(int)$booking_id;
                $prompt = "Booking #{$booking_id} for \"{$serviceTitle}\" is completed. Client: please leave a review for {$freelancerName} to help the community. <a href=\"{$url}\" class=\"leave-review-link inline-flex items-center gap-2 px-3 py-1 rounded-full bg-amber-600 text-white hover:bg-amber-700 transition-colors\" data-booking-id=\"{$booking_id}\"><svg xmlns=\"http://www.w3.org/2000/svg\" class=\"h-4 w-4\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M5 13l4 4L19 7\" /></svg>Leave a review</a>.";
                $this->systemBookingMessage($booking_id, (int)$b['client_id'], $prompt);
            } catch (Throwable $e) { /* ignore */ }

            // Notify the client in notifications center as well
            try {
                if (method_exists($this,'addNotification')) {
                    $this->addNotification((int)$b['client_id'], 'review_prompt', [
                        'booking_id'    => (int)$booking_id,
                        'service_id'    => (int)($b['service_id'] ?? 0),
                        'freelancer_id' => (int)($b['freelancer_id'] ?? 0),
                        'service_title' => (string)($serviceTitle ?? ''),
                        'freelancer_name' => (string)($freelancerName ?? ''),
                        'url'           => (string)$url
                    ]);
                }
            } catch (Throwable $e) { /* ignore */ }

            $this->releaseFundsIfEligible($booking_id,$actor_id);
        }

        return true;
    }

    function systemBookingMessage(int $booking_id,int $actor_id,string $body): void {
        $b = $this->fetchBookingWithContext($booking_id);
        if(!$b) return;
        $convId = $this->createOrGetGeneralConversation((int)$b['client_id'], (int)$b['freelancer_id']);
        if ($convId) {
            $this->addMessage($convId,$actor_id,$body,'system',$booking_id);
        }
    }

    function releaseFundsIfEligible(int $booking_id,int $actor_id): bool|string {
        $b = $this->fetchBookingWithContext($booking_id);
        if(!$b) return "Booking not found.";
        if ((float)$b['total_paid_amount'] + 0.001 < (float)$b['total_amount'])
            return "Not fully paid yet.";
        if ($b['escrow_status']==='released') return "Already released.";

        $commission = round((float)$b['total_amount'] * $this->commissionRate,2);
        $net = round((float)$b['total_amount'] - $commission,2);

        $pdo=$this->opencon();
        try {
            $this->begin();

            $csel = $pdo->prepare("SELECT commission_id FROM commissions WHERE booking_id=? LIMIT 1");
            $csel->execute([$booking_id]);
            if ($csel->fetch()) {
                $pdo->prepare("UPDATE commissions SET percentage=:p, amount=:a WHERE booking_id=:b")
                    ->execute([':p'=>$this->commissionRate*100, ':a'=>$commission, ':b'=>$booking_id]);
            } else {
                $pdo->prepare("INSERT INTO commissions (booking_id,percentage,amount,created_at)
                               VALUES (:b,:p,:a,NOW())")
                    ->execute([':b'=>$booking_id, ':p'=>$this->commissionRate*100, ':a'=>$commission]);
            }

            $freelancer_id=(int)$b['freelancer_id'];
            $wallet_id = $this->ensureWallet($freelancer_id,$pdo);

            $pdo->prepare("INSERT INTO wallet_transactions (wallet_id,booking_id,txn_type,amount,description,status,created_at)
                           VALUES (:w,:b,'credit',:amt,'Release booking funds','completed',NOW())")
                ->execute([':w'=>$wallet_id, ':b'=>$booking_id, ':amt'=>$net]);

            $pdo->prepare("UPDATE wallets SET balance=balance+:amt, updated_at=NOW() WHERE wallet_id=:w")
                ->execute([':amt'=>$net, ':w'=>$wallet_id]);

            $pdo->prepare("UPDATE bookings SET escrow_status='released', payment_status='released' WHERE booking_id=?")
                ->execute([$booking_id]);

            $this->systemBookingMessage($booking_id,$actor_id,
                "Funds released to freelancer. Commission ₱".number_format($commission,2)." retained.");

            $this->commit();
            return true;
        } catch(Throwable $e){
            $this->rollback();
            return "Release failed: ".$e->getMessage();
        }
    }

    private function ensureWallet(int $user_id, ?PDO $pdo=null): int {
        $pdo = $pdo ?: $this->opencon();
        $st=$pdo->prepare("SELECT wallet_id FROM wallets WHERE user_id=? LIMIT 1");
        $st->execute([$user_id]);
        if ($row=$st->fetch()) return (int)$row['wallet_id'];
        $pdo->prepare("INSERT INTO wallets (user_id,balance,pending,currency,updated_at)
                       VALUES (:u,0,0,'PHP',NOW())")->execute([':u'=>$user_id]);
        return (int)$pdo->lastInsertId();
    }

    /* ---------------- REVIEWS ---------------- */
    function bookingHasReviewFrom(int $booking_id,int $reviewer_id): bool {
        $st=$this->opencon()->prepare("SELECT 1 FROM reviews WHERE booking_id=? AND reviewer_id=? LIMIT 1");
        $st->execute([$booking_id,$reviewer_id]);
        return (bool)$st->fetchColumn();
    }
    function leaveReviewAsClient(int $booking_id,int $client_id,int $rating,string $comment=''): bool {
        $pdo=$this->opencon();
        $st=$pdo->prepare("SELECT freelancer_id,client_id,status FROM bookings WHERE booking_id=? LIMIT 1");
        $st->execute([$booking_id]);
        $b=$st->fetch();
        if(!$b) return false;
        if((int)$b['client_id']!==$client_id) return false;
        if(!in_array($b['status'],['delivered','completed'],true)) return false;
        if($this->bookingHasReviewFrom($booking_id,$client_id)) return false;
        return $this->createReview($booking_id,$client_id,(int)$b['freelancer_id'],$rating,$comment);
    }
    function createReview(int $booking_id,int $reviewer_id,int $reviewee_id,int $rating,string $comment=''): bool {
        if($rating<1||$rating>5) return false;
        $st=$this->opencon()->prepare("INSERT INTO reviews (booking_id,reviewer_id,reviewee_id,rating,comment,created_at)
                                       VALUES (:b,:r,:re,:ra,:c,NOW())");
        return $st->execute([
            ':b'=>$booking_id, ':r'=>$reviewer_id, ':re'=>$reviewee_id,
            ':ra'=>$rating, ':c'=>$comment
        ]);
    }
    function getFreelancerReviews(int $freelancer_id,int $limit=20): array {
        $st=$this->opencon()->prepare("SELECT rv.*,u.first_name,u.last_name,u.profile_picture,
                                              s.title AS service_title
                                       FROM reviews rv
                                       JOIN users u ON rv.reviewer_id=u.user_id
                                       LEFT JOIN bookings b ON rv.booking_id=b.booking_id
                                       LEFT JOIN services s ON b.service_id=s.service_id
                                       WHERE rv.reviewee_id=:f
                                       ORDER BY rv.created_at DESC
                                       LIMIT :l");
        $st->bindValue(':f',$freelancer_id,PDO::PARAM_INT);
        $st->bindValue(':l',$limit,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    // Batch fetch: recent reviews grouped by service for the provided service IDs
    // Returns array: [service_id => [ {review_id, service_id, rating, comment, created_at, reviewer_id, reviewer_name}... ]]
    function getRecentServiceReviews(array $serviceIds, int $perService=2, int $maxTotal=300): array {
        $result = [];
        $ids = array_values(array_unique(array_map('intval', $serviceIds)));
        if (count($ids) === 0) return $result;

        // Cap limits sanely
        $perService = max(1, min(10, (int)$perService));
        $maxTotal = max(10, min(1000, (int)$maxTotal));

        // Build IN clause placeholders
        $ph = [];$params = [];
        foreach ($ids as $i => $id) { $key = ":s{$i}"; $ph[] = $key; $params[$key] = $id; }
        $in = implode(',', $ph);

        // Heuristic overall limit to avoid fetching too many rows
        $overallLim = min($maxTotal, $perService * count($ids) * 3);

        $sql = "SELECT r.review_id, r.rating, r.comment, r.created_at, r.reviewer_id,
                       b.service_id,
                       u.first_name, u.last_name
                FROM reviews r
                JOIN bookings b ON r.booking_id = b.booking_id
                JOIN users u ON r.reviewer_id = u.user_id
                WHERE b.service_id IN ($in)
                ORDER BY r.created_at DESC
                LIMIT :lim";
        $pdo = $this->opencon();
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) { $st->bindValue($k, $v, PDO::PARAM_INT); }
        $st->bindValue(':lim', $overallLim, PDO::PARAM_INT);
        $st->execute();
        while ($row = $st->fetch()) {
            $sid = (int)$row['service_id'];
            if (!isset($result[$sid])) $result[$sid] = [];
            if (count($result[$sid]) >= $perService) continue; // enforce per-service cap
            $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
            $result[$sid][] = [
                'review_id'    => (int)$row['review_id'],
                'service_id'   => $sid,
                'rating'       => (int)$row['rating'],
                'comment'      => (string)($row['comment'] ?? ''),
                'created_at'   => (string)$row['created_at'],
                'reviewer_id'  => (int)$row['reviewer_id'],
                'reviewer_name'=> $name !== '' ? $name : 'User'
            ];
        }
        return $result;
    }

    // Batch compute aggregates for services: total reviews, sum of stars, average rating
    function getServiceReviewAggregates(array $serviceIds): array {
        $map = [];
        $ids = array_values(array_unique(array_map('intval', $serviceIds)));
        if (count($ids) === 0) return $map;

        $ph = [];$params = [];
        foreach ($ids as $i => $id) { $k = ":s{$i}"; $ph[] = $k; $params[$k] = $id; }
        $in = implode(',', $ph);
        $sql = "SELECT b.service_id,
                       COUNT(*) AS total_reviews,
                       SUM(r.rating) AS sum_stars,
                       AVG(r.rating) AS avg_rating
                FROM reviews r
                JOIN bookings b ON r.booking_id = b.booking_id
                WHERE b.service_id IN ($in)
                GROUP BY b.service_id";
        $pdo = $this->opencon();
        $st = $pdo->prepare($sql);
        foreach ($params as $k=>$v) { $st->bindValue($k, $v, PDO::PARAM_INT); }
        $st->execute();
        while ($row = $st->fetch()) {
            $sid = (int)$row['service_id'];
            $total = (int)$row['total_reviews'];
            $sum = (float)($row['sum_stars'] ?? 0);
            $avg = $total > 0 ? (float)$row['avg_rating'] : 0.0;
            $map[$sid] = [
                'total_reviews' => $total,
                'sum_stars'     => $sum,
                'avg_rating'    => $avg,
            ];
        }
        return $map;
    }

    /* ---------------- NOTIFICATIONS ---------------- */
    function addNotification(int $user_id,string $type,array $data=[]): bool {
        $st=$this->opencon()->prepare("INSERT INTO notifications (user_id,type,data,created_at)
                                       VALUES (:u,:t,:d,NOW())");
        return $st->execute([':u'=>$user_id,':t'=>$type,':d'=>json_encode($data)]);
    }

    /* ---------------- CONVERSATION VISIBILITY (PER-USER) ---------------- */
    private function ensureConversationUserStateTable(): void {
        try {
            $this->opencon()->query("CREATE TABLE IF NOT EXISTS conversation_user_state (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                conversation_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                hidden_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_conv_user (conversation_id, user_id),
                KEY idx_user_hidden (user_id, hidden_at),
                CONSTRAINT fk_cus_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(conversation_id) ON DELETE CASCADE,
                CONSTRAINT fk_cus_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Throwable $e) {
            // Best-effort; ignore if lacking permissions; features depending on it may degrade
        }
    }

    function userInConversation(int $user_id, int $conversation_id): bool {
        // Use distinct placeholders for the same value to avoid HY093 with native prepares
        $st = $this->opencon()->prepare("SELECT 1 FROM conversations WHERE conversation_id=:c AND (client_id=:u1 OR freelancer_id=:u2) LIMIT 1");
        $st->execute([':c'=>$conversation_id, ':u1'=>$user_id, ':u2'=>$user_id]);
        return (bool)$st->fetchColumn();
    }

    function hideConversationForUser(int $conversation_id, int $user_id): bool|string {
        $this->ensureConversationUserStateTable();
        if (!$this->userInConversation($user_id, $conversation_id)) return "Not a participant.";
        $sql = "INSERT INTO conversation_user_state (conversation_id, user_id, hidden_at)
                VALUES (:c,:u,NOW())
                ON DUPLICATE KEY UPDATE hidden_at=VALUES(hidden_at)";
        $pdo = $this->opencon();
        try {
            $st = $pdo->prepare($sql);
            $ok = $st->execute([':c'=>$conversation_id, ':u'=>$user_id]);
            return $ok ? true : "Failed to hide conversation.";
        } catch (PDOException $e) {
            // If table is missing for any reason, attempt to create and retry once
            $msg = $e->getMessage();
            if (strpos($msg, 'Base table or view not found') !== false || strpos($msg, '1146') !== false) {
                try {
                    $this->ensureConversationUserStateTable();
                    $st = $pdo->prepare($sql);
                    $ok = $st->execute([':c'=>$conversation_id, ':u'=>$user_id]);
                    return $ok ? true : "Failed to hide conversation.";
                } catch (Throwable $e2) {
                    return "Failed to hide conversation: ".$e2->getMessage();
                }
            }
            return "Failed to hide conversation: ".$e->getMessage();
        }
    }

    function unhideConversationForUser(int $conversation_id, int $user_id): bool|string {
        $this->ensureConversationUserStateTable();
        if (!$this->userInConversation($user_id, $conversation_id)) return "Not a participant.";
        $sql = "INSERT INTO conversation_user_state (conversation_id, user_id, hidden_at)
                VALUES (:c,:u,NULL)
                ON DUPLICATE KEY UPDATE hidden_at=NULL";
        $pdo = $this->opencon();
        try {
            $st = $pdo->prepare($sql);
            $ok = $st->execute([':c'=>$conversation_id, ':u'=>$user_id]);
            return $ok ? true : "Failed to unhide conversation.";
        } catch (Throwable $e) {
            return "Failed to unhide conversation: ".$e->getMessage();
        }
    }

    /* ------------ FREELANCER PAYMENT METHODS ------------ */
    function addFreelancerPaymentMethod(
    int $user_id,
    string $method_type,
    string $display_label,
    ?string $account_name,
    ?string $account_number,
    ?string $bank_name,     // kept for compat; unused for gcash/paymaya
    ?string $qr_code_url,
    array $extra=[]
): int|false {
    // Only gcash and paymaya now:
    if(!in_array($method_type,['gcash','paymaya'],true)) return false;
    $st=$this->opencon()->prepare("
        INSERT INTO freelancer_payment_methods
          (user_id,method_type,display_label,account_name,account_number,bank_name,qr_code_url,extra_json,is_active,created_at)
        VALUES (:u,:mt,:dl,:an,:ac,:bn,:qr,:ex,1,NOW())
    ");
    $ok=$st->execute([
        ':u'=>$user_id, ':mt'=>$method_type, ':dl'=>$display_label,
        ':an'=>$account_name, ':ac'=>$account_number, ':bn'=>$bank_name,
        ':qr'=>$qr_code_url, ':ex'=>$extra?json_encode($extra):null
    ]);
    return $ok ? (int)$this->opencon()->lastInsertId() : false;
}

    function listFreelancerPaymentMethods(int $user_id,bool $onlyActive=true): array {
        $sql="SELECT * FROM freelancer_payment_methods WHERE user_id=:u";
        if ($onlyActive) $sql.=" AND is_active=1";
        $sql.=" ORDER BY method_id DESC";
        $st=$this->opencon()->prepare($sql);
        $st->execute([':u'=>$user_id]);
        return $st->fetchAll();
    }

    function getFreelancerPaymentMethod(int $method_id,int $user_id): array|false {
        $st=$this->opencon()->prepare("SELECT * FROM freelancer_payment_methods WHERE method_id=:m AND user_id=:u LIMIT 1");
        $st->execute([':m'=>$method_id,':u'=>$user_id]);
        return $st->fetch();
    }

    function updateFreelancerPaymentMethod(int $method_id,int $user_id,array $fields): bool {
        if(!$fields) return false;
        $allowed=['display_label','account_name','account_number','bank_name','qr_code_url','extra_json','is_active'];
        $set=[]; $params=[':m'=>$method_id,':u'=>$user_id];
        foreach($fields as $k=>$v){
            if(in_array($k,$allowed,true)){
                if($k==='extra_json' && is_array($v)) $v=json_encode($v);
                $set[]="`$k`=:$k";
                $params[":$k"]=$v;
            }
        }
        if(!$set) return false;
        $sql="UPDATE freelancer_payment_methods SET ".implode(',',$set).",updated_at=NOW()
              WHERE method_id=:m AND user_id=:u";
        return $this->opencon()->prepare($sql)->execute($params);
    }

    function deleteFreelancerPaymentMethod(int $method_id,int $user_id): bool {
        $st=$this->opencon()->prepare("DELETE FROM freelancer_payment_methods WHERE method_id=:m AND user_id=:u");
        return $st->execute([':m'=>$method_id,':u'=>$user_id]);
    }

} // end class database