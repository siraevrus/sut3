<?php
/**
 * Transit Endpoint (Stub)
 * Система складского учета (SUT)
 */

ApiAuth::requireAuth();

switch ($method) {
    case 'GET':
        ApiResponse::success([], 'Transit endpoint - coming soon');
        break;
        
    default:
        ApiResponse::error('Method not allowed', 405);
}
?>