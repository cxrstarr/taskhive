<?php
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
const DEBUG_DB_PAY = true; // <--- Turn ON (true) only while diagnosing HY093, then OFF.

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
            $pdo = new PDO(
                dsn: 'mysql:host=localhost;dbname=taskhive;charset=utf8mb4',
                username: 'root',
                password: '',
                options: [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        }
        return $pdo;
    }
    function begin(){ $this->opencon()->beginTransaction(); }
    function commit(){ $this->opencon()->commit(); }
    function rollback(){ if ($this->opencon()->inTransaction()) $this->opencon()->rollBack(); }

    private function dbg(string $tag, mixed $data): void {
        if (!DEBUG_DB_PAY) return;
        $msg = '['.$tag.'] '.(is_string($data)?$data:json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        error_log($msg);
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
            $sql="SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.slug,s.created_at,
                         u.user_id AS freelancer_id,u.first_name,u.last_name,u.profile_picture
                  FROM services s
                  JOIN users u ON s.freelancer_id=u.user_id
                  WHERE s.status='active' AND (s.title LIKE :qt OR s.description LIKE :qd)
                  ORDER BY s.created_at DESC
                  LIMIT :o,:l";
            $st=$pdo->prepare($sql);
            $st->bindValue(':qt',$like,PDO::PARAM_STR);
            $st->bindValue(':qd',$like,PDO::PARAM_STR);
        } else {
            $st=$pdo->prepare("SELECT s.service_id,s.title,s.description,s.base_price,s.price_unit,s.slug,s.created_at,
                                      u.user_id AS freelancer_id,u.first_name,u.last_name,u.profile_picture
                               FROM services s
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

        if ($ua['user_type'] !== $ub['user_type']) {
            if ($ua['user_type']==='client') { $cid=$ua['user_id']; $fid=$ub['user_id']; }
            elseif ($ub['user_type']==='client') { $cid=$ub['user_id']; $fid=$ua['user_id']; }
            else { $cid=min($userA,$userB); $fid=max($userA,$userB); }
        } else {
            $cid=min($userA,$userB);
            $fid=max($userA,$userB);
        }

        $pdo=$this->opencon();
        $sel=$pdo->prepare("SELECT conversation_id FROM conversations
                            WHERE client_id=? AND freelancer_id=? AND conversation_type='general' LIMIT 1");
        $sel->execute([$cid,$fid]);
        if ($row=$sel->fetch()) return (int)$row['conversation_id'];

        $ins=$pdo->prepare("INSERT INTO conversations (conversation_type,client_id,freelancer_id,created_at)
                            VALUES ('general',:c,:f,NOW())");
        $ins->execute([':c'=>$cid,':f'=>$fid]);
        return (int)$pdo->lastInsertId();
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
    function listConversationsWithUnread(int $user_id,int $limit=100,int $offset=0): array {
        $limit=max(1,(int)$limit);
        $offset=max(0,(int)$offset);
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
          WHERE c.client_id=:outer_client
             OR c.freelancer_id=:outer_freelancer
          ORDER BY (c.last_message_at IS NULL) ASC,
                   c.last_message_at DESC,
                   c.created_at DESC
          LIMIT $offset,$limit";
        $st=$this->opencon()->prepare($sql);
        $st->bindValue(':sub_sender',$user_id,PDO::PARAM_INT);
        $st->bindValue(':outer_client',$user_id,PDO::PARAM_INT);
        $st->bindValue(':outer_freelancer',$user_id,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    function getLatestBookingBetweenUsers(int $client_id,int $freelancer_id, ?array $statuses=null): array|false {
        $pdo = $this->opencon();
        $where = "client_id=:c AND freelancer_id=:f";
        $params = [':c'=>$client_id, ':f'=>$freelancer_id];

        if ($statuses && count($statuses)>0) {
            // build IN list
            $in = implode(',', array_fill(0, count($statuses), '?'));
            $sql = "SELECT booking_id FROM bookings WHERE $where AND status IN ($in) ORDER BY created_at DESC LIMIT 1";
            $st  = $pdo->prepare($sql);
            $i=1;
            $st->bindValue(':c',$client_id,PDO::PARAM_INT);
            $st->bindValue(':f',$freelancer_id,PDO::PARAM_INT);
            foreach ($statuses as $s) $st->bindValue($i++, $s);
            $st->execute();
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
        $sql="SELECT COUNT(*)
              FROM messages m
              INNER JOIN conversations c ON m.conversation_id=c.conversation_id
              WHERE m.read_at IS NULL
                AND m.sender_id <> :sender
                AND (c.client_id = :as_client OR c.freelancer_id = :as_freelancer)";
        $st=$this->opencon()->prepare($sql);
        $st->bindValue(':sender',$user_id,PDO::PARAM_INT);
        $st->bindValue(':as_client',$user_id,PDO::PARAM_INT);
        $st->bindValue(':as_freelancer',$user_id,PDO::PARAM_INT);
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

    // Expected amount per phase
    $expected = match($phase){
        'full_advance','postpaid_full' => (float)$b['total_amount'],
        'downpayment' => round((float)$b['total_amount'] * ((float)$b['downpayment_percent']/100),2),
        'balance'     => round((float)$b['total_amount'] - (float)$b['paid_upfront_amount'],2),
        default       => null
    };
    if ($expected === null || $amount <= 0 || abs($expected - $amount) > 0.01) {
        return "Payment amount mismatch (expected ₱".number_format((float)$expected,2).").";
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

        // 2) Update booking financials without reusing the same named placeholder
        if (in_array($phase,['full_advance','downpayment'],true)) {
            $sqlUp = "UPDATE bookings
                      SET paid_upfront_amount = paid_upfront_amount + :amt1,
                          total_paid_amount   = total_paid_amount + :amt2,
                          escrow_status='holding',
                          payment_status='escrowed',
                          updated_at=NOW()
                      WHERE booking_id=:bid";
            $upd = $pdo->prepare($sqlUp);
            $upd->execute([
                ':amt1'=>$amount,
                ':amt2'=>$amount,
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
                              ELSE payment_status
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

            // Post to the general conversation between the pair
            $convId = $this->createOrGetGeneralConversation((int)$b['client_id'], (int)$b['freelancer_id']);
            if ($convId) {
                $actorLabel=$actor_role==='freelancer'?'Freelancer':'Client';
                $body="$actorLabel performed action '$action'. Booking now '$newStatus'.";
                $this->addMessage($convId,$actor_id,$body,'system',$booking_id);
            }
        } catch(Throwable $e){}

        if ($newStatus==='completed') {
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
        $st=$this->opencon()->prepare("SELECT rv.*,u.first_name,u.last_name
                                       FROM reviews rv
                                       JOIN users u ON rv.reviewer_id=u.user_id
                                       WHERE rv.reviewee_id=:f
                                       ORDER BY rv.created_at DESC
                                       LIMIT :l");
        $st->bindValue(':f',$freelancer_id,PDO::PARAM_INT);
        $st->bindValue(':l',$limit,PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    }

    /* ---------------- NOTIFICATIONS ---------------- */
    function addNotification(int $user_id,string $type,array $data=[]): bool {
        $st=$this->opencon()->prepare("INSERT INTO notifications (user_id,type,data,created_at)
                                       VALUES (:u,:t,:d,NOW())");
        return $st->execute([':u'=>$user_id,':t'=>$type,':d'=>json_encode($data)]);
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