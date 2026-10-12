<?php
/**
 * WP-CLI command wrapper for provisioning actions.
 */
if ( ! defined( 'WPINC' ) ) {
    return;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {

    /**
     * WP-CLI wrapper class.
     *
     * Exposes a small command surface to invoke the provisioning flow from
     * the command line (useful for CI, automation and debugging). The command
     * constructs a minimal parameter set and calls the provisioning handler
     * in-process to avoid doing an HTTP self-request.
     */
    class Chatbot_Assistant_CLI {

        /**
         * Ejecuta automáticamente los Pasos 1-2 de provisioning.
         * Ideal para scripts de instalación automatizada.
         *
         * ## EXAMPLES
         *     wp chatbot provision-auto
         *
         * @subcommand provision-auto
         */
        public function provision_auto( $args, $assoc_args ) {
            WP_CLI::line( 'Iniciando automatización de provisioning (Pasos 1-2)...' );

            // Ejecutar automáticamente Pasos 1-2 (invocación explícita, se salta el guard is_admin())
            Chatbot_Assistant_Provision::maybe_run_pending_provision( true );

            // Verificar resultado
            $store = get_option( 'chatbot_provision', array() );

            if ( ! empty( $store['wc_verified'] ) ) {
                WP_CLI::success( 'Provisioning completado exitosamente!' );
                WP_CLI::line( '' );
                WP_CLI::line( 'Estado del Tenant:' );
                WP_CLI::line( '  • tenant_id: ' . ( $store['tenant_id'] ?? 'N/A' ) );
                WP_CLI::line( '  • current_step: ' . ( $store['current_step'] ?? 'N/A' ) );
                WP_CLI::line( '  • wc_verified: true' );
                WP_CLI::line( '  • status: ' . ( $store['status'] ?? 'N/A' ) );
                WP_CLI::line( '' );
                WP_CLI::line( 'Proximos pasos: Completar Pasos 3-5 (WhatsApp, personalizacion, activacion) desde el admin panel' );
            } else {
                WP_CLI::warning( 'Provisioning incompleto o con errores.' );
                WP_CLI::line( '' );
                WP_CLI::line( 'Bitácora de eventos:' );
                $this->audit( array(), array() );
            }
        }

        /**
         * Muestra el estado actual del provisioning.
         *
         * ## EXAMPLES
         *     wp chatbot status
         *
         * @subcommand status
         */
        public function status( $args, $assoc_args ) {
            $store = get_option( 'chatbot_provision', array() );

            if ( empty( $store['tenant_id'] ) ) {
                WP_CLI::warning( 'Tenant no inicializado aún.' );
                return;
            }

            WP_CLI::line( 'Estado del Provisioning:' );
            WP_CLI::line( '' );
            WP_CLI::line( sprintf( 'tenant_id: %s', $store['tenant_id'] ) );
            WP_CLI::line( sprintf( 'current_step: %d', $store['current_step'] ?? 0 ) );
            WP_CLI::line( sprintf( 'status: %s', $store['status'] ?? 'unknown' ) );
            WP_CLI::line( sprintf( 'wc_verified: %s', ! empty( $store['wc_verified'] ) ? 'true' : 'false' ) );
            WP_CLI::line( sprintf( 'wa_verified: %s', ! empty( $store['wa_verified'] ) ? 'true' : 'false' ) );
            if ( ! empty( $store['phone_number'] ) ) {
                WP_CLI::line( sprintf( 'wa_phone: %s', $store['phone_number'] ) );
            }
        }

        /**
         * Muestra la bitácora del onboarding (últimos eventos, el más nuevo primero). Es la misma
         * que el panel muestra solo en modo soporte (CHATBOT_DEBUG); acá no hace falta la constante.
         *
         * ## EXAMPLES
         *     wp chatbot audit
         *
         * @subcommand audit
         */
        public function audit( $args, $assoc_args ) {
            $audit = Chatbot_Assistant_Backend_Client::mask_secrets( Chatbot_Assistant_Provision::get_audit_log() );
            if ( empty( $audit ) ) {
                WP_CLI::line( 'La bitácora está vacía.' );
                return;
            }
            foreach ( $audit as $entry ) {
                WP_CLI::line( sprintf(
                    '[%s] %s: %s%s',
                    $entry['created_at'] ?? '',
                    strtoupper( $entry['level'] ?? 'info' ),
                    $entry['message'] ?? '',
                    empty( $entry['context'] ) ? '' : ' ' . wp_json_encode( $entry['context'] )
                ) );
            }
        }

        /**
         * Limpia la bitácora de auditoría.
         *
         * ## EXAMPLES
         *     wp chatbot clear-audit
         *
         * @subcommand clear-audit
         */
        public function clear_audit( $args, $assoc_args ) {
            Chatbot_Assistant_Provision::clear_audit_log();
            WP_CLI::success( 'Bitácora limpiada.' );
        }
    }

    // Registrar bajo `wp chatbot <subcommand>`
    WP_CLI::add_command( 'chatbot', 'Chatbot_Assistant_CLI' );
}
