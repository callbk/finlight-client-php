<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known problem codes of FinlightApiException::code(). Open: unknown codes stay on FinlightApiException.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class ProblemCode
{
    public const V_A_L_I_D_A_T_I_O_N_F_A_I_L_E_D = 'VALIDATION_FAILED';
    public const C_U_R_S_O_R_I_N_V_A_L_I_D = 'CURSOR_INVALID';
    public const C_U_R_S_O_R_M_I_S_M_A_T_C_H = 'CURSOR_MISMATCH';
    public const U_N_A_U_T_H_O_R_I_Z_E_D = 'UNAUTHORIZED';
    public const F_O_R_B_I_D_D_E_N = 'FORBIDDEN';
    public const N_O_T_F_O_U_N_D = 'NOT_FOUND';
    public const R_A_T_E_L_I_M_I_T_E_D = 'RATE_LIMITED';
    public const I_N_T_E_R_N_A_L_E_R_R_O_R = 'INTERNAL_ERROR';

    /** @var list<string> */
    public const ALL = [self::V_A_L_I_D_A_T_I_O_N_F_A_I_L_E_D, self::C_U_R_S_O_R_I_N_V_A_L_I_D, self::C_U_R_S_O_R_M_I_S_M_A_T_C_H, self::U_N_A_U_T_H_O_R_I_Z_E_D, self::F_O_R_B_I_D_D_E_N, self::N_O_T_F_O_U_N_D, self::R_A_T_E_L_I_M_I_T_E_D, self::I_N_T_E_R_N_A_L_E_R_R_O_R];

    /** @var array<string, string> problem code => type URL */
    public const TYPES = [
        self::V_A_L_I_D_A_T_I_O_N_F_A_I_L_E_D => 'https://docs.finlight.me/errors/validation-failed',
        self::C_U_R_S_O_R_I_N_V_A_L_I_D => 'https://docs.finlight.me/errors/cursor-invalid',
        self::C_U_R_S_O_R_M_I_S_M_A_T_C_H => 'https://docs.finlight.me/errors/cursor-mismatch',
        self::U_N_A_U_T_H_O_R_I_Z_E_D => 'https://docs.finlight.me/errors/unauthorized',
        self::F_O_R_B_I_D_D_E_N => 'https://docs.finlight.me/errors/forbidden',
        self::N_O_T_F_O_U_N_D => 'https://docs.finlight.me/errors/not-found',
        self::R_A_T_E_L_I_M_I_T_E_D => 'https://docs.finlight.me/errors/rate-limited',
        self::I_N_T_E_R_N_A_L_E_R_R_O_R => 'https://docs.finlight.me/errors/internal-error',
    ];

    private function __construct()
    {
    }
}
