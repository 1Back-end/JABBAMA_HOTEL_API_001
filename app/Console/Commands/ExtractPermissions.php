<?php

namespace App\Console\Commands;

use App\Models\Permission;
use App\Models\PermissionCategory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use ReflectionClass;
use ReflectionMethod;

class ExtractPermissions extends Command
{
    protected $signature = 'permissions:extract';
    protected $description = 'Synchronise les permissions avec les annotations des contrôleurs et les catégorise par menu.';
    protected string $defaultManualModule = '';


    protected array $manualPermissions = [
        'view_passations_notification_pending' => [
            'description' => 'Afficher les notifications de passations en attente',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_cancel' => [
            'description' => 'Afficher les notifications de passations annulées',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_in_discuss' => [
            'description' => 'Afficher les notifications de passations en discussion',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_no_gap' => [
            'description' => 'Afficher les notifications de passations sans écart',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_with_gap' => [
            'description' => 'Afficher les notifications de passations avec écart',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_closed' => [
            'description' => 'Afficher les notifications de passations clôturées',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_rejected' => [
            'description' => 'Afficher les notifications de passations rejetées',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_icons_passations_notification' => [
            'description' => 'Afficher l\'icône des notifications de passations',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_as_read_passations_notification' => [
            'description' => 'Marquer une notification de passation comme lue',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_all_as_read_passations_notification' => [
            'description' => 'Marquer toutes les notifications de passations comme lues',
            'category' => 'Notifications de passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_decisional_passations_notifications' => [
            'description' => 'Afficher toutes les notifications décisionnelles de passations de stocks',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_pending_by_admin' => [
            'description' => 'Afficher les notifications décisionnelles de passations en attente',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_cancel_by_admin' => [
            'description' => 'Afficher les notifications décisionnelles de passations annulées',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_in_discuss_by_admin' => [
            'description' => 'Afficher les notifications décisionnelles de passations en discussion',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_no_gap_by_admin' => [
            'description' => 'Afficher les notifications décisionnelles de passations sans écart',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_with_gap_by_admin' => [
            'description' => 'Afficher les notifications décisionnelles de passations avec écart',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_closed_by_admin' => [
            'description' => 'Afficher les notifications décisionnelles de passations clôturées',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_passations_notification_rejected_by_admin' => [
            'description' => 'Afficher les notifications décisionnelles de passations rejetées',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_icons_decisional_passations_notification' => [
            'description' => 'Afficher l\'icône des notifications décisionnelles de passations',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_as_read_decisional_passations_notification' => [
            'description' => 'Marquer une notification décisionnelle de passation comme lue',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_all_as_read_decisional_passations_notification' => [
            'description' => 'Marquer toutes les notifications décisionnelles de passations comme lues',
            'category' => 'Notifications décisionnelles de passations',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

        'view_role_related_data' => [
            'description' => 'Partager les mêmes informations entre utilisateurs ayant le même rôle.',
            'category' => 'Gestion des utilisateurs',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR,STOCK',
        ],
        'view_decisional_post' => [
            'description' => 'Afficher et accéder au module du Poste Décisionnel.',
            'category' => 'Navigation et Administration',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR,STOCK',
        ],

        'view_functional_posts' => [
            'description' => 'Afficher et gérer les postes fonctionnels.',
            'category' => 'Navigation et Administration',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR,STOCK',
        ],
        'view_settings_menu' => [
            'description' => 'Accéder aux paramètres généraux de l’application.',
            'category' => 'Navigation et Administration',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR,STOCK',
        ],
        'view_restaurant_bar_reports' => [
            'description' => 'Afficher et consulter les rapports de la gestion du restaurant et du bar.',
            'category' => 'Navigation et Administration',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_transferred_orders' => [
            'description' => 'Permettre aux gestionnaires de stock de voir toutes les commandes qui leur ont été transférées.',
            'category' => 'Gestion des commandes',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_orders_in_draft' => [
            'description' => 'Permettre aux gestionnaires de stock de voir toutes les commandes en Brouillon.',
            'category' => 'Gestion des commandes',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_transferred_supplies' => [
            'description' => 'Permettre aux gestionnaires de stock de voir tous les approvisionnements qui leur ont été transférées.',
            'category' => 'Gestion des approvisionnements',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_access_setting' => [
            'description' => 'Accéder aux paramètrages d\'accès (Utilisateurs,Permissions,Rôle)',
            'category' => 'Gestion des utilisateurs',
            'modules' => ['Gestion des stocks', 'Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR,STOCK',
        ],
        'view_access_setting_stocks' => [
            'description' => 'Accéder aux paramètrages de stocks',
            'category' => 'Gestion des articles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_access_setting_restaurants' => [
            'description' => 'Accéder aux paramètres du restaurant et du Bar',
            'category' => 'Permissions supplémentaires',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_access_setting_others_transferts' => [
            'description' => 'Accéder aux paramètres des autres transferts de stocks',
            'category' => 'Gestion des passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_access_for_dashboard' => [
            'description' => 'Accéder au tableau dashboard',
            'category' => 'Gestion des statistiques',
            'modules' => ['Gestion des stocks'],
            'code' => 'RESTAURANT,BAR,STOCK',
        ],
        'view_access_for_applied_remise' => [
            'description' => 'Appliquer une remise lors de la prise d\'une commande' ,
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_access_for_date_orders' => [
            'description' => 'Changer la date lors de la prise d\'une commande' ,
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'definied_warehouses_is_used_for_restaurant' => [
            'description' => 'Définir l\'entrepôt cuisine utilisée pour le restaurant' ,
            'category' => 'Gestion des entrepôts',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'definied_warehouses_is_other_transformation' => [
            'description' => 'Définir l\'entrepôt de transformation des boissons pour le restaurant' ,
            'category' => 'Gestion des entrepôts',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'definied_warehouses_is_used_for_stock_flow' => [
            'description' => 'Définir l\'entrepôt utilisé pour l\'écoulement des stocks',
            'category' => 'Gestion des entrepôts',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'definied_warehouses_is_bar_warehouse' => [
            'description' => 'Définir l\'entrepôt bar utilisé pour le restaurant' ,
            'category' => 'Gestion des entrepôts',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'see_menus_for_order_restaurant' => [
            'description' => 'Afficher les menus d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant'],
            'code' => 'RESTAURANT',
        ],
        'see_drinks_for_order_restaurant' => [
            'description' => 'Afficher les boissons d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du bar'],
            'code' => 'BAR',
        ],
        'view_all_notification_transferred' => [
            'description' => 'Afficher les notifications en transférée',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_preparation' => [
            'description' => 'Afficher les notifications en cours de préparation',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_rejected' => [
            'description' => 'Afficher les notifications en rejetée',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_defective' => [
            'description' => 'Afficher les notifications en défectieux',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_ready' => [
            'description' => 'Afficher les notifications en prêt',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_delivered' => [
            'description' => 'Afficher les notifications en servie',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_rejected_after_validation' => [
            'description' => 'Afficher les notifications en rejet du servi',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_cancel_for_new_update' => [
            'description' => 'Afficher les notifications en rejet du prêt',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_partial_delivered' => [
            'description' => 'Afficher les notifications en servie partiellement',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_partial_completed' => [
            'description' => 'Afficher les notifications en prêt partiellement',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_kitchen_notifications' => [
            'description' => 'Afficher les notifications des commandes contenant des menus',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant'],
            'code' => 'RESTAURANT',
        ],
        'view_bar_notifications' => [
            'description' => 'Afficher les notifications des commandes contenant des boissons',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du bar'],
            'code' => 'BAR',
        ],
        'mark_all_notifications_as_read' => [
            'description' => 'Marquer comme lues toutes les notifications visibles en un seule clic',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_kitchen_orders' => [
            'description' => 'Afficher les commandes contenant des menus',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant'],
            'code' => 'RESTAURANT',
        ],
        'view_bar_orders' => [
            'description' => 'Afficher les commandes contenant des boissons',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du bar'],
            'code' => 'BAR',
        ],
        'view_factures_for_orders' => [
            'description' => 'Afficher l\'interface d\'encaissements des factures',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'change_order_payment_date' => [
            'description' => 'Changer la date pour effectuer les encaissements des factures antérieures',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_extended_cash_register_date' => [
            'description' => 'Changer la date pour afficher les données antérieures de la fiche de caisse',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_extended_cash_register_date_recovery' => [
            'description' => 'Afficher l\'interface de recouvrements des factures',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_main_courante' => [
            'description' => 'Changer la date pour afficher les données antérieures de la main courante',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'change_expense_payment_date' => [
            'description' => 'Changer la date pour effectuer des dépenses antérieures',
            'category' => 'Gestion des dépenses',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'change_other_cash_in_date' => [
            'description' => 'Changer la date pour effectuer des encaissements antérieurs',
            'category' => 'Gestion des autres encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_other_cash_in_history' => [
            'description' => "Afficher l'historique des recouvrements des factures",
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'save_regulations_for_orders_items' => [
            'description' => 'Enregistrer les encaissements des menus d\'une commande',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant'],
            'code' => 'RESTAURANT',
        ],
        'view_deposit' => [
            'description' => 'Afficher le cumulatif des arrhes des clients',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'refund_deposit_histories' => [
            'description' => 'Effectuer le remboursement des arrhes des clients',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'refund_all_deposit_histories' => [
            'description' => 'Effectuer le remboursement intégral des arrhes des clients',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_cashflow_details' => [
            'description' => 'Afficher les détails des flux de caisse (Encaissements et Dépenses)',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'save_regulations_for_orders_items_and_drinks' => [
            'description' => 'Enregistrer l\'encaissement globale d\'une facture',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'save_regulations_for_orders_drinks' => [
            'description' => 'Enregistrer les encaissements des boissons d\'une commande',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du bar'],
            'code' => 'BAR',
        ],
        'cancel_regulations_for_orders_drinks' => [
            'description' => 'Annuler les encaissements des boissons d\'une commande',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du bar'],
            'code' => 'BAR',
        ],
        'cancel_regulations_for_orders_items' => [
            'description' => 'Annuler les encaissements des menus d\'une commande',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant'],
            'code' => 'RESTAURANT',
        ],
        'cancel_regulations_for_orders_items_and_drinks' => [
            'description' => 'Annuler les encaissements globale d\'une facture',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_cash_register_sheet' => [
            'description' => 'Afficher la fiche de caisse',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_unit_price_and_total_price' => [
            'description' => 'Afficher le prix unitaire, le prix total des items et le montant total d\'une commande',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],

        'view_all_notification_transferred_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande transférée',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_preparation_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en cours de préparation',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_rejected_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en rejetée',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_defective_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en défectieux',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_ready_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en prêt',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_delivered_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en servie',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_rejected_after_validation_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en rejet du servi',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'read_notification_by_admin' => [
            'description' => 'Marquer comme lue les notifications des décisionnaires un à un',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'mark_all_decisional_notifications_as_read' => [
            'description' => 'Marquer comme lue toute les notifications des décisionnaires en un clic',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_cancel_for_new_update_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en rejet du prêt',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_partial_delivered_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en servie partiellement',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_all_notification_in_partial_completed_by_admin' => [
            'description' => 'Afficher les notifications d\'une commande en prêt partiellement',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_icons_notificatins_for_decisional' => [
            'description' => 'Afficher l\'icone des notifications décisionnelles',
            'category' => 'Notifications décisionnaires du restaurant',
            'modules' => ['Gestion du restaurant','Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_icons_notificatins_for_user' => [
            'description' => 'Afficher l\'icône des notifications',
            'category' => 'Notifications du restaurant opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_draft' => [
            'description' => 'Afficher les notifications à l\'état Brouillon',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_open' => [
            'description' => 'Afficher les notifications transférées',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_validated' => [
            'description' => 'Afficher les notifications en cours de livraison',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_in_discuss' => [
            'description' => 'Afficher les notifications en discussion',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_rejected' => [
            'description' => 'Afficher les notifications rejetées',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_cancel' => [
            'description' => 'Afficher les notifications annulées',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_partially_closed' => [
            'description' => 'Afficher les notifications clôturées partiellement',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_stock_notification_closed' => [
            'description' => 'Afficher les notifications clôturées totalement',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_icons_notifications_for_stock' => [
            'description' => 'Afficher l\'icône des notifications',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_stock_notification_as_read' => [
            'description' => 'Marquer comme lue une notification',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_all_stock_notifications_as_read' => [
            'description' => 'Marquer comme lues toutes les notifications',
            'category' => 'Notifications des stocks opérationnelles',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_icons_notifications_decisional_stock' => [
            'description' => 'Afficher l\'icône des notifications',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_decisional_stock_notification_draft' => [
            'description' => 'Afficher les notifications brouillon',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

        'view_decisional_stock_notification_open' => [
            'description' => 'Afficher les notifications transférées',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

        'view_decisional_stock_notification_validated' => [
            'description' => 'Afficher les notifications en cours de livraison',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

        'view_decisional_stock_notification_in_discuss' => [
            'description' => 'Afficher les notifications en discussion',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

        'view_decisional_stock_notification_rejected' => [
            'description' => 'Afficher les notifications rejetées',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

        'view_decisional_stock_notification_cancel' => [
            'description' => 'Afficher les notifications annulées',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_decisional_stock_notification_partially_closed' => [
            'description' => 'Afficher les notifications clôturées partiellement',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

        'view_decisional_stock_notification_closed' => [
            'description' => 'Afficher les notifications clôturées totalement',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_decisional_stock_notification_as_read' => [
            'description' => 'Marquer une notification comme lue',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'mark_all_decisional_stock_notifications_as_read' => [
            'description' => 'Marquer toutes les notifications comme lues',
            'category' => 'Notifications des stocks décisionnels',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'save_recoveries_for_orders_items_and_drinks' => [
            'description' => 'Effectuer le recouvrement global d\'une facture',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'save_recoveries_for_orders_drinks' => [
            'description' => 'Effectuer les recouvrements des boissons',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du bar'],
            'code' => 'BAR',
        ],
        'cancel_recoveries_for_orders_drinks' => [
            'description' => 'Annuler les recouvrements des boissons',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du bar'],
            'code' => 'BAR',
        ],
        'save_recoveries_for_orders_items' => [
            'description' => 'Effectuer les recouvrements des menus',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant'],
            'code' => 'RESTAURANT',
        ],
        'cancel_recoveries_for_orders_items' => [
            'description' => 'Annuler les recouvrements des menus',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant'],
            'code' => 'RESTAURANT',
        ],
        'cancel_recoveries_for_orders_items_and_drinks' => [
            'description' => 'Annuler le recouvrement global d\'une facture',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_extended_cash_situation_sheets' => [
            'description' => 'Afficher l\'interface de la feuille de situation',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_extended_situation_sheets_date' => [
            'description' => 'Changer la date pour afficher les données antérieures de la fiche de situation',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_extended_operational_monitoring_date' => [
            'description' => 'Changer la date pour afficher les données antérieures du suivie d\'exploitation',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_extended_operational_monitoring' => [
            'description' => 'Afficher l\'interface du suivie d\'exploitation',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_promoter_report' => [
            'description' => 'Afficher l\'interface du rapport promoteur',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Autres Modules'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_main_courante_promotor' => [
            'description' => 'Afficher l\'interface de la main courante',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_cash_register_sheet_promotor' => [
            'description' => 'Afficher l\'interface de la fiche de caisse',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'print_operational_monitoring_promotor' => [
            'description' => 'Imprimer le rapport de suivi d\'exploitation',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'print_extended_operational_monitoring' => [
            'description' => 'Imprimer le rapport du suivi d\'exploitation',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'print_extended_cash_situation_sheets' => [
            'description' => 'Imprimer le rapport de la feuille de situation',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'print_main_courante' => [
            'description' => 'Imprimer le rapport de la main courante',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_calendar_basic' => [
            'description' => 'Afficher le calendrier basique dans le rapport promoteur',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_calendar_advanced' => [
            'description' => 'Afficher le calendrier avancé dans le rapport promoteur',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_others_reports' => [
            'description' => 'Afficher l\'interface des autres rapports',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_access_for_reports_restaurant' => [
            'description' => 'Accéder au menu des rapports des rapports et statistiques du restaurant/bar',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_access_for_setting_restaurant' => [
            'description' => 'Accéder au menu du paramétrage de la fiche de caisse du restaurant',
            'category' => 'Gestion des encaissements',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_access_for_sales_restaurant' => [
            'description' => 'Accéder au menu de facturation du restaurant',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_past_kitchen_orders' => [
            'description' => 'Permettre d\'afficher les données antérieures des commandes',
            'category' => 'Gestion des commandes du restaurant',
            'modules' => ['Gestion du restaurant', 'Gestion du bar'],
            'code' => 'RESTAURANT,BAR',
        ],
        'view_past_simple_orders' => [
            'description' => 'Permettre d\'afficher les données antérieures des commandes',
            'category' => 'Gestion des commandes',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_past_supply_orders' => [
            'description' => 'Permettre d\'afficher les données antérieures des approvisionnements',
            'category' => 'Gestion des approvisionnements',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_past_stocks_deductions' => [
            'description' => 'Permettre d\'afficher les données antérieures des déductions de stocks',
            'category' => 'Gestion des déductions de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_past_send_stocks_orders' => [
            'description' => 'Permettre d\'afficher les données antérieures des passations de stocks',
            'category' => 'Gestion des passations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],
        'view_past_stocks_regulations' => [
            'description' => 'Permettre d\'afficher les données antérieures des régulations de stocks',
            'category' => 'Gestion des régularisations de stocks',
            'modules' => ['Gestion des stocks'],
            'code' => 'STOCK',
        ],

    ];

    public function handle(): void
    {
        $controllersPath = app_path('Http/Controllers');
        $permissions = [];

        $systemUser = User::where('login', 'SYSTEM')->first();
        $systemId = $systemUser?->id ?? 1;
        $superAdminRole = Role::find(1);

        foreach ($this->getControllers($controllersPath) as $controller) {
            $this->extractPermissionsFromController($controller, $permissions);
        }

        $this->info("\n--- Synchronisation des permissions et modules ---");

        $validPermissions = [];

        foreach ($permissions as $controller => $methods) {
            ksort($methods);
            usort($methods, fn($a, $b) => strcmp($a['permission'], $b['permission']));
            foreach ($methods as $perm) {
                $categoryName = $perm['category'] ?? 'AUTRES';
                $modules = $perm['modules'] ?? [$this->defaultManualModule];
                $codeModules = $perm['code_modules'] ?? [];
                $permissionCode = $perm['permission_code'] ?? null;

                $category = PermissionCategory::firstOrCreate(
                    ['libelle' => $categoryName],
                    [
                        'description' => $categoryName,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                $permission = Permission::updateOrCreate(
                    ['name' => $perm['permission']],
                    [
                        'code' => $permissionCode,
                        'description' => $perm['permission_desc'] ?? '',
                        'category_id' => $category->id,
                        'system' => true,
                        'active' => true,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                $validPermissions[] = $permission->name;

                foreach ($modules as $index => $moduleName) {
                    $moduleSlug = \Str::slug($moduleName);
                    $codeModule = $codeModules[$index] ?? null;

                    $module = \App\Models\ModuleApplications::firstOrCreate(
                        ['slug' => $moduleSlug],
                        [
                            'name' => $moduleName,
                            'code' => $codeModule,
                            'description' => $moduleName,
                            'is_active' => true,
                            'created_by' => $systemId,
                            'updated_by' => $systemId,
                        ]
                    );

                    if ($codeModule && $module->code !== $codeModule) {
                        $module->code = $codeModule;
                        $module->save();
                    }

                    $this->info("✅ Module synchronisé : {$module->name} (" . ($codeModule ?? 'N/A') . ")");

                    if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                        $module->permissions()->attach($permission->id, [
                            'created_by' => $systemId,
                            'updated_by' => $systemId,
                        ]);
                    }

                    $permission->module_uuid = $module->uuid;
                    $permission->save();
                }

                if ($permission->wasRecentlyCreated && $superAdminRole) {
                    $superAdminRole->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }

                $this->info("✅ Permission synchronisée : {$permission->name}");
            }
        }

        $manualPermissions = $this->manualPermissions;
        uksort($manualPermissions, fn($a, $b) => strcmp($a['description'] ?? '', $b['description'] ?? ''));
        foreach ($this->manualPermissions as $name => $data) {
            $categoryName = $data['category'] ?? 'AUTRES';
            $modules = $data['modules'] ?? [$this->defaultManualModule];

            // CORRECTION SÉCURISÉE ICI : Utilisation de isset() pour éviter l'erreur sur code_module
            $codeModules = $data['code_modules'] ?? (isset($data['code_module']) ? [$data['code_module']] : []);

            $permissionCode = $data['code'] ?? null;

            $category = PermissionCategory::firstOrCreate(
                ['libelle' => $categoryName],
                [
                    'description' => $categoryName,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            $permission = Permission::updateOrCreate(
                ['name' => $name],
                [
                    'code' => $permissionCode,
                    'description' => $data['description'] ?? '',
                    'category_id' => $category->id,
                    'system' => true,
                    'active' => true,
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]
            );

            $validPermissions[] = $permission->name;

            foreach ($modules as $index => $moduleName) {
                $moduleSlug = \Str::slug($moduleName);
                $codeModule = $codeModules[$index] ?? null;

                $module = \App\Models\ModuleApplications::firstOrCreate(
                    ['slug' => $moduleSlug],
                    [
                        'name' => $moduleName,
                        'code' => $codeModule,
                        'description' => $moduleName,
                        'is_active' => true,
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]
                );

                if ($codeModule && $module->code !== $codeModule) {
                    $module->code = $codeModule;
                    $module->save();
                }

                $this->info("✅ Module synchronisé : {$module->name}");

                if (!$module->permissions()->where('permission_id', $permission->id)->exists()) {
                    $module->permissions()->attach($permission->id, [
                        'created_by' => $systemId,
                        'updated_by' => $systemId,
                    ]);
                }

                $permission->module_uuid = $module->uuid;
                $permission->save();
            }

            if ($permission->wasRecentlyCreated && $superAdminRole) {
                $superAdminRole->permissions()->attach($permission->id, [
                    'created_by' => $systemId,
                    'updated_by' => $systemId,
                ]);
            }

            $this->info("✅ Permission manuelle synchronisée : {$permission->name}");
        }

        // Suppression des permissions obsolètes
        Permission::where('system', true)
            ->whereNotIn('name', $validPermissions)
            ->get()
            ->each(function ($permission) {
                $permission->delete();
                $this->warn("🗑️ Permission supprimée : {$permission->name}");
            });

        // Suppression automatique des catégories qui n'ont plus aucune permission liée
        $usedCategoryIds = Permission::pluck('category_id')->unique()->filter();
        PermissionCategory::whereNotIn('id', $usedCategoryIds)->each(function ($category) {
            $category->delete();
            $this->warn("🗑️ Catégorie supprimée : {$category->libelle}");
        });

        // Suppression des modules orphelins
        $usedModuleIds = \DB::table('module_permission')->pluck('module_uuid')->unique()->filter();
        \App\Models\ModuleApplications::whereNotIn('uuid', $usedModuleIds)
            ->get()
            ->each(function ($module) {
                $module->delete();
                $this->warn("🗑️ Module supprimé : {$module->name}");
            });

        \App\Models\ModuleApplications::whereDoesntHave('permissions')
            ->get()
            ->each(function ($module) {
                $module->delete();
                $this->warn("🗑️ Module supprimé car vide : {$module->name}");
            });

        $this->info("\n✅ Synchronisation complète et nettoyage terminés !");
    }

    /**
     * Extraction des permissions depuis un contrôleur avec catégorie, modules multiples et codes multiples
     */
    private function extractPermissionsFromController(string $controller, array &$permissions): void
    {
        if (!class_exists($controller)) return;

        $reflection = new \ReflectionClass($controller);

        $doc = $reflection->getDocComment() ?: '';
        $category = $this->extractTagValue($doc, '@permission_category') ?: 'Autres';

        $modules = $this->extractTagValues($doc, '@permission_module');
        if (empty($modules)) {
            $modules = ['Autres Modules'];
        }

        $codeModules = $this->extractTagValues($doc, '@code_module');

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $docComment = $method->getDocComment();
            if (!$docComment) continue;

            $permission     = $this->extractTagValue($docComment, '@permission');
            $permissionCode = $this->extractTagValue($docComment, '@code');
            $permissionDesc = $this->extractTagValue($docComment, '@permission_desc') ?? '';

            if ($permission) {
                $permissions[$controller][$method->getName()] = [
                    'permission'      => $permission,
                    'permission_code' => $permissionCode,
                    'permission_desc' => $permissionDesc,
                    'category'        => $category,
                    'modules'         => $modules,
                    'code_modules'    => $codeModules,
                ];
            }
        }
    }

    /**
     * Récupère toutes les valeurs d'un tag dans un docblock
     */
    private function extractTagValues(string $doc, string $tag): array
    {
        preg_match_all('/' . preg_quote($tag, '/') . '\s+(.+)/', $doc, $matches);
        return isset($matches[1]) ? array_map('trim', $matches[1]) : [];
    }

    /**
     * Récupère tous les contrôleurs d'un répertoire récursivement
     */
    private function getControllers(string $directory, string $namespace = 'App\\Http\\Controllers'): array
    {
        $controllers = [];

        foreach (scandir($directory) as $file) {
            if (in_array($file, ['.', '..'])) continue;

            $fullPath = $directory . DIRECTORY_SEPARATOR . $file;

            if (is_dir($fullPath)) {
                $controllers = array_merge(
                    $controllers,
                    $this->getControllers($fullPath, $namespace . '\\' . $file)
                );
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $controllers[] = $namespace . '\\' . pathinfo($file, PATHINFO_FILENAME);
            }
        }

        return $controllers;
    }

    /**
     * Récupère la catégorie définie dans le contrôleur via @permission_category
     */
    private function extractControllerCategory(string $controller): ?string
    {
        $reflection = new \ReflectionClass($controller);
        return $reflection->getDocComment()
            ? $this->extractTagValue($reflection->getDocComment(), '@permission_category')
            : null;
    }

    /**
     * Extrait une seule valeur d’un tag dans un docblock
     */
    private function extractTagValue(string $doc, string $tag): ?string
    {
        return preg_match('/' . preg_quote($tag, '/') . '\s+(.+)/', $doc, $matches)
            ? trim($matches[1])
            : null;
    }
}
