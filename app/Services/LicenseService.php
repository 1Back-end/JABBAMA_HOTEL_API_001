<?php

namespace App\Services;

class LicenseService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Récupère la liste des codes modules actifs pour l'application.
     *
     * Vous pourrez par la suite remplacer ce retour statique par une requête
     * en base de données, la lecture d'un fichier de licence chiffré, ou un appel API.
     *
     * @return array
     */
    public function getActiveModules(): array
    {
        return ['STOCK', 'RESTAURANT','BAR'];
    }

    /**
     * Vérifie si un module spécifique est actif.
     *
     * @param string $moduleCode
     * @return bool
     */
    public function isModuleActive(string $moduleCode): bool
    {
        return in_array(strtoupper($moduleCode), $this->getActiveModules());
    }
}
