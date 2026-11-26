<?php
/**
 * Teste temporario da maquina de estados de mute (criterios de aceite).
 * Roda com: php database/mute-state-test.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use GamingCalls\Server\SignalingServer;

// Instancia sem construtor (nao precisa do event loop)
$ref = new ReflectionClass(SignalingServer::class);
/** @var SignalingServer $srv */
$srv = $ref->newInstanceWithoutConstructor();

$apply = $ref->getMethod('applyMuteTransition');
$apply->setAccessible(true);
$stateOf = $ref->getMethod('muteStateOf');
$stateOf->setAccessible(true);

$ROOM = 1;
$HOST = 'sess-host';
$A = 'sess-a';
$B = 'sess-b';

$fail = 0;
$pass = 0;

function check(string $label, bool $cond): void
{
    global $fail, $pass;
    if ($cond) { $pass++; echo "  ok   $label\n"; }
    else { $fail++; echo "  FAIL $label\n"; }
}

function reset1(SignalingServer $srv, ReflectionClass $ref, int $room, array $sessions): void
{
    $prop = $ref->getProperty('muteStates');
    $prop->setAccessible(true);
    $prop->setValue($srv, []);
}

$reset = function () use ($ref, $srv, $ROOM, $HOST, $A, $B) {
    $p = $ref->getProperty('muteStates');
    $p->setAccessible(true);
    $p->setValue($srv, [$ROOM => [
        $HOST => ['state' => 'UNMUTED', 'by' => null],
        $A    => ['state' => 'UNMUTED', 'by' => null],
        $B    => ['state' => 'UNMUTED', 'by' => null],
    ]]);
};

$call = function (string $target, string $action, string $actor, string $role) use ($apply, $srv, $ROOM) {
    return $apply->invoke($srv, $ROOM, $target, $action, $actor, $role);
};
$state = fn(string $s) => $stateOf->invoke($srv, $ROOM, $s);

echo "1) Participante se muta; so ele se desmuta\n";
$reset();
check('A se muta -> SELF_MUTED', $call($A, 'mute-self', $A, 'participant')['ok'] && $state($A) === 'SELF_MUTED');
check('B (participante) tenta desmutar A -> negado', !$call($A, 'unmute-self', $B, 'participant')['ok'] && $state($A) === 'SELF_MUTED');
check('A se desmuta -> UNMUTED', $call($A, 'unmute-self', $A, 'participant')['ok'] && $state($A) === 'UNMUTED');

echo "2) Host muta A; A se desmuta\n";
$reset();
check('host muta A -> HOST_MUTED', $call($A, 'host-mute', $HOST, 'host')['ok'] && $state($A) === 'HOST_MUTED');
check('A se desmuta -> UNMUTED', $call($A, 'unmute-self', $A, 'participant')['ok'] && $state($A) === 'UNMUTED');

echo "3) Host muta e depois libera\n";
$reset();
check('host muta A -> HOST_MUTED', $call($A, 'host-mute', $HOST, 'host')['ok'] && $state($A) === 'HOST_MUTED');
check('host libera A -> UNMUTED', $call($A, 'host-unmute', $HOST, 'host')['ok'] && $state($A) === 'UNMUTED');

echo "4) Admin tenta desmutar SELF_MUTED -> negado\n";
$reset();
$call($A, 'mute-self', $A, 'participant');
$r = $call($A, 'host-unmute', 'sess-admin', 'admin');
check('negado com ADMIN_CANNOT_UNMUTE', !$r['ok'] && $r['reason'] === 'ADMIN_CANNOT_UNMUTE' && $state($A) === 'SELF_MUTED');

echo "5) Admin tenta desmutar HOST_MUTED -> negado\n";
$reset();
$call($A, 'host-mute', $HOST, 'host');
$r = $call($A, 'host-unmute', 'sess-admin', 'admin');
check('negado com ADMIN_CANNOT_UNMUTE', !$r['ok'] && $r['reason'] === 'ADMIN_CANNOT_UNMUTE' && $state($A) === 'HOST_MUTED');

echo "6) Participante tenta desmutar outro -> negado\n";
$reset();
$call($A, 'host-mute', $HOST, 'host');
$r = $call($A, 'unmute-self', $B, 'participant');
check('negado com NOT_SELF', !$r['ok'] && $r['reason'] === 'NOT_SELF' && $state($A) === 'HOST_MUTED');

echo "7) Host tenta desmutar quem se mutou -> negado\n";
$reset();
$call($A, 'mute-self', $A, 'participant');
$r = $call($A, 'host-unmute', $HOST, 'host');
check('negado com SELF_MUTED', !$r['ok'] && $r['reason'] === 'SELF_MUTED' && $state($A) === 'SELF_MUTED');

echo "8) Host tenta mutar quem ja se mutou -> continua SELF_MUTED\n";
$reset();
$call($A, 'mute-self', $A, 'participant');
$r = $call($A, 'host-mute', $HOST, 'host');
check('negado e estado preservado', !$r['ok'] && $state($A) === 'SELF_MUTED');

echo "9) Transicao negada nao altera o estado (sem efeito colateral)\n";
$reset();
$call($A, 'mute-self', $A, 'participant');
$before = $state($A);
$call($A, 'host-unmute', $HOST, 'host');
$call($A, 'unmute-self', $B, 'participant');
check('estado inalterado', $state($A) === $before);

echo "10) Idempotencia\n";
$reset();
$call($A, 'mute-self', $A, 'participant');
$r = $call($A, 'mute-self', $A, 'participant');
check('mutar duas vezes continua SELF_MUTED', $r['ok'] && $state($A) === 'SELF_MUTED');

echo "\n$pass passaram, $fail falharam\n";
exit($fail === 0 ? 0 : 1);
