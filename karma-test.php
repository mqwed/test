<?php
/*
 * Сервис для рассылки уведомлений об истекающих подписках. Запускается по крону раз в час.
 */

function send_email($email, $from, $to, $subj, $body)
{
}

function check_email($email)
{
    return 1;
}

/*
 * защита от множественных запусков
 */
function lockRuntime()
{
    global $lockRuntimeFP; // глобальный, чтобы лок держался до конца работы скрипта
    $f = basename($_SERVER['SCRIPT_NAME']);
    $lockRuntimeFP = fopen("/tmp/$f.lock", 'w');
    if (!flock($lockRuntimeFP, LOCK_EX | LOCK_NB))
        die('locked');
}

lockRuntime();

// в таблицу users надо добавить поле expiration_notificated timestamp null
// если предполагаются разные уведомления, то эффективнее создать специальную таблицу под них
// нужен индекс по validts
// и unique index по email на обе таблицы
$days_before_notify = 3;
$rows = $db->query("
    select u.username, u.email 
    from users u 
    where u.validts < adddate(now(), ?)
    and (u.expiration_notificated is null or u.validts > adddate(u.expiration_notificated, ?))   
    and u.confirmed
", [$days_before_notify, $days_before_notify])->iassoc();

// в $rows идут не сразу все данные, это итератор, который фетчит по одной строке
foreach ($rows as $row)
{
    // из поля users.confirmed (если оно =1) разве не следует, что почта валидная? зачем ее еще раз проверять в сервисе уведомлений?
    $email_row = $db->query("select * from emails where email=?", [$row['email']])->row();
    if (!@$email_row['checked'])
    {
        $valid = check_email($row['email']);
        $db->query("
            insert into emails 
            set email=?, checked=1, valid=? 
            on duplicate key update checked=1, valid = values(valid)
            ", [$row['email'], $valid]);

        if (!$valid)
            continue;
    }
    else if (!$email_row['valid'])
        continue;

    // тут непонятно зачем дублируется email в задании
    send_email($row['email'], 'no-reply@karma8.io', $row['email'], "karma8.io subscription expiration", "{$row['username']}, your subscription is expiring soon");

    $db->query("update users set expiration_notificated=now() where email=?", [$row['email']]);  // лучше заменить на users.id, но тогда его надо добавить на БД
}

