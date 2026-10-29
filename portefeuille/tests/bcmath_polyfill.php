<?php
/**
 * Polyfill BCMath en PHP pur, utilisé UNIQUEMENT par les tests quand l'extension bcmath est absente.
 * (L'application elle-même exige l'extension bcmath, activée par défaut avec XAMPP.)
 * Seules les fonctions utilisées par le projet sont fournies : bcadd, bcsub, bcmul, bcdiv, bccomp.
 */
if (!function_exists('bcadd')) {

    function _bcp_parse(string $n): array
    {
        $n = trim($n);
        $neg = false;
        if ($n !== '' && ($n[0] === '-' || $n[0] === '+')) {
            $neg = $n[0] === '-';
            $n = substr($n, 1);
        }
        $parts = explode('.', $n, 2);
        $int = ltrim($parts[0], '0');
        $frac = $parts[1] ?? '';
        return [$neg, $int === '' ? '0' : $int, $frac];
    }

    /** Chiffres (sans signe) de $n multipliés par 10^$w (w >= nb de décimales de $n). */
    function _bcp_digits(string $int, string $frac, int $w): string
    {
        $d = ltrim($int . str_pad($frac, $w, '0'), '0');
        return $d === '' ? '0' : $d;
    }

    function _bcp_cmp(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }
        return strcmp($a, $b) <=> 0;
    }

    function _bcp_add(string $a, string $b): string
    {
        $r = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; $i--, $j--) {
            $s = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $r = ($s % 10) . $r;
            $carry = intdiv($s, 10);
        }
        return $r === '' ? '0' : $r;
    }

    /** a - b avec a >= b. */
    function _bcp_sub(string $a, string $b): string
    {
        $r = '';
        $borrow = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0; $i--, $j--) {
            $s = (int) $a[$i] - ($j >= 0 ? (int) $b[$j] : 0) - $borrow;
            $borrow = $s < 0 ? 1 : 0;
            $r = ($s + 10 * $borrow) . $r;
        }
        $r = ltrim($r, '0');
        return $r === '' ? '0' : $r;
    }

    function _bcp_mul(string $a, string $b): string
    {
        $res = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $res[$i + $j + 1] += (int) $a[$i] * (int) $b[$j];
            }
        }
        for ($k = count($res) - 1; $k > 0; $k--) {
            $res[$k - 1] += intdiv($res[$k], 10);
            $res[$k] %= 10;
        }
        $r = ltrim(implode('', $res), '0');
        return $r === '' ? '0' : $r;
    }

    /** Division entière a / b (b != 0). */
    function _bcp_div(string $a, string $b): string
    {
        $q = '';
        $reste = '0';
        for ($i = 0; $i < strlen($a); $i++) {
            $reste = ltrim($reste . $a[$i], '0');
            $reste = $reste === '' ? '0' : $reste;
            $d = 0;
            while (_bcp_cmp($reste, $b) >= 0) {
                $reste = _bcp_sub($reste, $b);
                $d++;
            }
            $q .= $d;
        }
        $q = ltrim($q, '0');
        return $q === '' ? '0' : $q;
    }

    /** Formate (signe, chiffres, décimales implicites $w) puis tronque à $scale décimales. */
    function _bcp_format(bool $neg, string $digits, int $w, int $scale): string
    {
        $digits = str_pad($digits, $w + 1, '0', STR_PAD_LEFT);
        $int = $w > 0 ? substr($digits, 0, -$w) : $digits;
        $frac = $w > 0 ? substr($digits, -$w) : '';
        $frac = substr(str_pad($frac, $scale, '0'), 0, $scale);
        $out = $int . ($scale > 0 ? '.' . $frac : '');
        $zero = trim($int . $frac, '0') === '';
        return ($neg && !$zero ? '-' : '') . $out;
    }

    function _bcp_addsub(string $a, string $b, int $scale, bool $soustraire): string
    {
        [$na, $ia, $fa] = _bcp_parse($a);
        [$nb, $ib, $fb] = _bcp_parse($b);
        if ($soustraire) {
            $nb = !$nb;
        }
        $w = max(strlen($fa), strlen($fb));
        $da = _bcp_digits($ia, $fa, $w);
        $db = _bcp_digits($ib, $fb, $w);
        if ($na === $nb) {
            return _bcp_format($na, _bcp_add($da, $db), $w, $scale);
        }
        if (_bcp_cmp($da, $db) >= 0) {
            return _bcp_format($na, _bcp_sub($da, $db), $w, $scale);
        }
        return _bcp_format($nb, _bcp_sub($db, $da), $w, $scale);
    }

    function bcadd(string $a, string $b, ?int $scale = 0): string
    {
        return _bcp_addsub($a, $b, $scale ?? 0, false);
    }

    function bcsub(string $a, string $b, ?int $scale = 0): string
    {
        return _bcp_addsub($a, $b, $scale ?? 0, true);
    }

    function bcmul(string $a, string $b, ?int $scale = 0): string
    {
        [$na, $ia, $fa] = _bcp_parse($a);
        [$nb, $ib, $fb] = _bcp_parse($b);
        $da = _bcp_digits($ia, $fa, strlen($fa));
        $db = _bcp_digits($ib, $fb, strlen($fb));
        return _bcp_format($na !== $nb, _bcp_mul($da, $db), strlen($fa) + strlen($fb), $scale ?? 0);
    }

    function bcdiv(string $a, string $b, ?int $scale = 0): string
    {
        [$na, $ia, $fa] = _bcp_parse($a);
        [$nb, $ib, $fb] = _bcp_parse($b);
        $scale = $scale ?? 0;
        $da = _bcp_digits($ia, $fa, strlen($fa));
        $db = _bcp_digits($ib, $fb, strlen($fb));
        if ($db === '0') {
            throw new DivisionByZeroError('Division by zero');
        }
        // (A / 10^fa) / (B / 10^fb) = A * 10^fb / (B * 10^fa) ; on veut $scale décimales
        $num = $da . str_repeat('0', $scale + strlen($fb));
        $den = $db . str_repeat('0', strlen($fa));
        return _bcp_format($na !== $nb, _bcp_div($num, $den), $scale, $scale);
    }

    function bccomp(string $a, string $b, ?int $scale = 0): int
    {
        $scale = $scale ?? 0;
        $ta = bcadd($a, '0', $scale);
        $tb = bcadd($b, '0', $scale);
        $diff = bcsub($ta, $tb, $scale);
        if (trim($diff, '-0.') === '') {
            return 0;
        }
        return $diff[0] === '-' ? -1 : 1;
    }
}
