<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
    echo '<div class="alert alert-danger">{{Accès refusé : connectez-vous en administrateur.}}</div>';
    return;
}
require_once dirname(__FILE__) . '/../core/class/ChargePlanner.class.php';

$days = ChargePlannerFormat::DAYS;
$upcoming = array();
foreach (array((int) date('Y'), (int) date('Y') + 1) as $y) {
    foreach (ChargePlannerCalendar::frenchHolidays($y) as $date => $label) {
        if ($date >= date('Y-m-d') && count($upcoming) < 6) {
            $upcoming[$date] = $label;
        }
    }
}

/** Ligne « commande » avec bouton de sélection. */
function cpCmdRow($key, $label, $type, $help = '') {
    echo '<div class="form-group"><label class="col-md-4 control-label">' . $label;
    if ($help !== '') {
        echo ' <sup><i class="fas fa-question-circle tooltips" title="' . $help . '"></i></sup>';
    }
    echo '</label><div class="col-md-6"><div class="input-group">';
    echo '<input class="configKey form-control roundedLeft" data-l1key="' . $key . '">';
    echo '<span class="input-group-btn"><a class="btn btn-default roundedRight chargePlannerConfigSelectCmd" data-type="' . $type . '" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>';
    echo '</div></div></div>';
}

/** Ligne « nombre ». */
function cpNumberRow($key, $label, $placeholder, $unit = '', $help = '') {
    echo '<div class="form-group"><label class="col-md-4 control-label">' . $label;
    if ($help !== '') {
        echo ' <sup><i class="fas fa-question-circle tooltips" title="' . $help . '"></i></sup>';
    }
    echo '</label><div class="col-md-3"><div class="input-group">';
    echo '<input type="number" step="any" class="configKey form-control" data-l1key="' . $key . '" placeholder="' . $placeholder . '">';
    if ($unit !== '') {
        echo '<span class="input-group-addon">' . $unit . '</span>';
    }
    echo '</div></div></div>';
}

$regLabels = array(
    'Limites' => array(
        'P_COMPTEUR_MAX' => array('{{Limite compteur EDF}}', 'W', ''),
        'P_MAISON_MAX' => array('{{Limite conso maison / tableau}}', 'W', ''),
        'U' => array('{{Tension}}', 'V', ''),
        'AMP_MIN' => array('{{Intensité minimum de charge}}', 'A', ''),
        'AMP_MAX' => array('{{Intensité maximum}}', 'A', ''),
        'MARGE_SECURITE' => array('{{Marge de planification sous les limites}}', 'W', ''),
        'MARGE_URGENCE' => array('{{Marge d\'urgence (mesure réelle)}}', 'W', '{{Si la mesure réelle dépasse la limite moins cette marge, réduction immédiate.}}'),
    ),
    'Mode Eco (batterie maison)' => array(
        'SOC_START' => array('{{Démarrage à partir de}}', '%', ''),
        'SOC_START_IMMEDIAT' => array('{{Démarrage immédiat à partir de}}', '%', ''),
        'SOC_STOP' => array('{{Arrêt sous}}', '%', ''),
        'SOC_CIBLE_BASSE' => array('{{Cible batterie basse}}', '%', ''),
        'SOC_CIBLE_HAUTE' => array('{{Cible batterie haute}}', '%', ''),
        'P_BAT_CIBLE_MAX' => array('{{Puissance batterie visée (SOC bas)}}', 'W', ''),
        'P_BAT_CIBLE_MIN' => array('{{Puissance batterie visée (SOC haut)}}', 'W', ''),
        'DEADBAND' => array('{{Bande morte}}', 'W', ''),
        'DELAI_DEMARRAGE_ECO' => array('{{Stabilité avant démarrage}}', 's', ''),
        'DELAI_MONTEE_ECO' => array('{{Délai entre deux montées}}', 's', ''),
        'PAS_MAX_MONTEE' => array('{{Pas de montée max}}', 'A', ''),
        'PAS_MAX_BAISSE' => array('{{Pas de baisse max}}', 'A', ''),
    ),
    'Mode Normal (anti-yoyo)' => array(
        'DELAI_MONTEE_AMP' => array('{{Délai avant montée}}', 's', ''),
        'DELAI_BAISSE_AMP' => array('{{Délai avant petite baisse}}', 's', ''),
        'ECART_MIN_MONTEE' => array('{{Écart minimum pour monter}}', 'A', ''),
        'SEUIL_BAISSE_IMMEDIATE' => array('{{Baisse immédiate à partir de}}', 'A', ''),
    ),
    'Pause / reprise et détection voiture' => array(
        'DELAI_MIN_TOGGLE' => array('{{Délai mini entre deux pause/reprise}}', 's', ''),
        'DELAI_STATUT_MAX' => array('{{Attente max du statut après une bascule}}', 's', ''),
        'SEUIL_VE_ACTIF' => array('{{Puissance où la voiture « absorbe »}}', 'W', ''),
        'DELAI_VE_INACTIF' => array('{{Eco : borne écartée après}}', 's', ''),
        'DELAI_RETEST_INACTIF' => array('{{Eco : nouvel essai au bout de}}', 's', ''),
        'DELAI_GRACE_DEMARRAGE' => array('{{Délai de grâce après démarrage}}', 's', ''),
        'DELAI_RETRY_PRIO_OFF' => array('{{Modes mixtes : nouvel essai priorisation Off}}', 's', ''),
    ),
);
$scenarios = array();
foreach (scenario::all() as $sc) {
    $scenarios[$sc->getId()] = $sc->getHumanName();
}
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-power-off"></i> {{Fonctionnement}}</legend>
    <div class="alert alert-info">
      {{<b>Observation</b> : le plugin calcule la régulation et les objectifs à chaque mesure, et écrit dans son log ce qu'il ferait (avec la consigne du scénario de référence à côté), mais n'envoie aucune commande. <b>Actif</b> : le plugin pilote les bornes ; le scénario de référence est désactivé automatiquement (et réactivé si vous revenez en observation).}}
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Fonctionnement}}</label>
      <div class="col-md-3">
        <select class="configKey form-control" data-l1key="fonctionnement">
          <option value="observation">{{Observation (aucune commande envoyée)}}</option>
          <option value="actif">{{Actif (le plugin pilote les bornes)}}</option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Scénario de référence}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Ancien scénario de pilotage. En observation, son état sert de base de calcul et de comparaison ; au passage en actif, son état est repris puis il est désactivé.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <select class="configKey form-control" data-l1key="ref_scenario">
          <option value="">{{Aucun}}</option>
          <?php foreach ($scenarios as $id => $name) { ?>
            <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></option>
          <?php } ?>
        </select>
      </div>
    </div>
    <?php cpCmdRow('trigger_cmd', '{{Déclencheur de la régulation (info)}}', 'info', '{{Chaque nouvelle valeur de cette commande lance un cycle (au plus un toutes les X secondes ci-dessous). Un cycle est aussi forcé chaque minute.}}'); ?>
    <?php cpNumberRow('reg_interval', '{{Intervalle minimum entre deux cycles}}', '10', 's'); ?>

    <legend><i class="fas fa-tachometer-alt"></i> {{Mesures}}</legend>
    <?php
    cpCmdRow('conso_maison', '{{Consommation maison / tableau (W)}}', 'info');
    cpCmdRow('conso_edf', '{{Consommation EDF (W, négatif = injection)}}', 'info');
    cpCmdRow('prod_solaire', '{{Production solaire (W)}}', 'info');
    cpCmdRow('soc_batterie', '{{Batterie maison : charge (%)}}', 'info');
    cpCmdRow('p_batterie', '{{Batterie maison : puissance (W, positif = charge)}}', 'info');
    cpCmdRow('prod_pv', '{{Production PV onduleur (W)}}', 'info');
    ?>

    <?php foreach (ChargePlanner::BORNES as $b) { ?>
      <legend><i class="fas fa-charging-station"></i> {{Borne}} <?php echo $b; ?></legend>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Nom}}</label>
        <div class="col-md-3"><input class="configKey form-control" data-l1key="borne<?php echo $b; ?>_name"></div>
      </div>
      <?php
      cpCmdRow('borne' . $b . '_status', '{{Statut ID (info)}}', 'info', '{{Code statut numérique de l\'API Wallbox.}}');
      cpCmdRow('borne' . $b . '_pince', '{{Puissance mesurée par la pince (info, W)}}', 'info');
      cpCmdRow('borne' . $b . '_slider', '{{Consigne d\'intensité (action curseur, A)}}', 'action');
      cpCmdRow('borne' . $b . '_toggle', '{{Pause / reprise (action bascule)}}', 'action');
      cpCmdRow('borne' . $b . '_refresh', '{{Rafraîchir (action)}}', 'action', '{{Exécutée avant chaque cycle en fonctionnement actif, comme dans le scénario.}}');
      cpCmdRow('borne' . $b . '_energy', '{{Énergie de la session (info, kWh)}}', 'info', '{{Sert à estimer le % des voitures entre deux remontées du constructeur.}}');
      cpNumberRow('borne' . $b . '_pmax', '{{Puissance maximum}}', '7400', 'W');
      ?>
    <?php } ?>

    <legend><i class="fas fa-eye"></i> {{Observation : reprise des anciens virtuels}}</legend>
    <div class="help-block col-md-offset-4">{{En observation, les modes et la priorisation sont lus sur ces commandes (vos virtuels actuels) et recopiés dans les équipements du plugin. Laissez vide pour utiliser directement ceux du plugin.}}</div>
    <?php
    cpCmdRow('obs_mode1_source', '{{Mode borne 1 (info)}}', 'info');
    cpCmdRow('obs_mode2_source', '{{Mode borne 2 (info)}}', 'info');
    cpCmdRow('obs_prio_source', '{{Priorisation (info)}}', 'info');
    ?>

    <legend><i class="fas fa-sliders-h"></i> {{Régulation}}
      <a class="btn btn-xs btn-default" id="bt_cpToggleReg" style="margin-left:10px;"><i class="fas fa-eye"></i> {{Afficher / masquer}}</a>
    </legend>
    <div id="div_cpReg" style="display:none;">
      <div class="help-block col-md-offset-4">{{Laisser vide pour garder la valeur du scénario V4 (indiquée en grisé).}}</div>
      <?php
      foreach ($regLabels as $group => $fields) {
          echo '<h5 class="col-md-offset-2"><b>' . $group . '</b></h5>';
          foreach ($fields as $key => $f) {
              cpNumberRow('reg_' . $key, $f[0], ChargePlannerRegulator::DEFAULTS[$key], $f[1], $f[2]);
          }
      }
      ?>
    </div>

    <legend><i class="fas fa-calendar-alt"></i> {{Jours creux}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Jours de la semaine creux toute la journée}}</label>
      <div class="col-md-8">
        <?php foreach ($days as $n => $label) { ?>
          <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="offpeak_day_<?php echo $n; ?>"><?php echo $label; ?></label>
        <?php } ?>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Jours fériés (France)}}</label>
      <div class="col-md-8">
        <label class="checkbox-inline"><input type="checkbox" class="configKey" data-l1key="offpeak_holidays">{{Compter les jours fériés comme jours creux}}</label>
        <div class="help-block">{{Prochains :}}
          <?php
          $parts = array();
          foreach ($upcoming as $date => $label) {
              $parts[] = date('d/m/Y', strtotime($date)) . ' (' . $label . ')';
          }
          echo htmlspecialchars(implode(', ', $parts), ENT_QUOTES, 'UTF-8');
          ?>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Autres jours creux}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Une date par ligne, au format JJ/MM/AAAA ou AAAA-MM-JJ.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <textarea class="configKey form-control" data-l1key="offpeak_extra" rows="3" placeholder="24/12/2026"></textarea>
      </div>
    </div>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Heure de démarrage du boost}}</label>
      <div class="col-md-2">
        <input type="time" class="configKey form-control" data-l1key="boost_time">
      </div>
    </div>
  </fieldset>
</form>
<script>
/*
 * Contournement d'une course dans le noyau Jeedom : getSelectModal() appelle
 * mod_insertCmd.setOptions() avant que la fenêtre cmd.human.insert ait
 * exécuté son script quand elle est ouverte depuis une autre fenêtre. On
 * pré-déclare l'objet avec un setOptions d'attente que le script de la
 * fenêtre remplace dès qu'il est chargé.
 */
function chargePlannerPickCmd(type, input) {
  if (window.mod_insertCmd == undefined) {
    window.mod_insertCmd = function () {};
    window.mod_insertCmd.options = { cmd: {}, eqLogic: {}, object: {} };
  }
  var waiting = function (opts, tries) {
    tries = tries || 0;
    if (window.mod_insertCmd.setOptions !== waiting) {
      window.mod_insertCmd.setOptions(opts);
    } else if (tries < 100) {
      setTimeout(function () { waiting(opts, tries + 1); }, 50);
    }
  };
  if (typeof window.mod_insertCmd.setOptions !== 'function') {
    window.mod_insertCmd.setOptions = waiting;
  }
  jeedom.cmd.getSelectModal({ cmd: { type: type || 'info' } }, function (result) {
    input.value = result.human;
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });
}
document.querySelectorAll('.chargePlannerConfigSelectCmd').forEach(function (btn) {
  btn.addEventListener('click', function () {
    chargePlannerPickCmd(btn.getAttribute('data-type'), btn.closest('.input-group').querySelector('input'));
  });
});
document.getElementById('bt_cpToggleReg').addEventListener('click', function () {
  var div = document.getElementById('div_cpReg');
  div.style.display = (div.style.display === 'none') ? '' : 'none';
});
</script>
