<?php

declare(strict_types=1);

namespace Finlight;

/**
 * Known codes of a connection.error frame. Open.
 *
 * Values are plain strings. Compare with ===. ALL lists the values known to this SDK version.
 */
final class ConnectionErrorCode
{
    public const P_I_N_G_O_N_D_E_F_A_U_L_T_R_O_U_T_E = 'PING_ON_DEFAULT_ROUTE';
    public const H_A_N_D_S_H_A_K_E_F_A_I_L_E_D = 'HANDSHAKE_FAILED';
    public const V_A_L_I_D_A_T_I_O_N_F_A_I_L_E_D = 'VALIDATION_FAILED';
    public const S_E_A_R_C_H_F_A_I_L_E_D = 'SEARCH_FAILED';
    public const I_N_T_E_R_N_A_L_E_R_R_O_R = 'INTERNAL_ERROR';

    /** @var list<string> */
    public const ALL = [self::P_I_N_G_O_N_D_E_F_A_U_L_T_R_O_U_T_E, self::H_A_N_D_S_H_A_K_E_F_A_I_L_E_D, self::V_A_L_I_D_A_T_I_O_N_F_A_I_L_E_D, self::S_E_A_R_C_H_F_A_I_L_E_D, self::I_N_T_E_R_N_A_L_E_R_R_O_R];

    private function __construct()
    {
    }
}
