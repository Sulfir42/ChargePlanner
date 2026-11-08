<?php
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');
if (!isConnect('admin')) {
    echo '<div class="alert alert-danger">{{Accès refusé : connectez-vous en administrateur.}}</div>';
    return;
}
require_once dirname(__FILE__) . '/../core/class/ChargePlannerCalendar.class.php';

$days = ChargePlannerFormat::DAYS;
$year = (int) date('Y');
$holidays = ChargePlannerCalendar::frenchHolidays($year);
$upcoming = array();
foreach (array($year, $year + 1) as $y) {
    foreach (ChargePlannerCalendar::frenchHolidays($y) as $date => $label) {
        if ($date >= date('Y-m-d') && count($upcoming) < 6) {
            $upcoming[$date] = $label;
        }
    }
}
$borneFields = array(
    'mode' => array('{{Mode actuel (info)}}', 'info', '{{Commande info qui donne le mode de la borne (texte « Normal » / « Eco »).}}'),
    'normal' => array('{{Passer en Normal (action)}}', 'action', ''),
    'eco' => array('{{Passer en Eco (action)}}', 'action', ''),
    'status' => array('{{Statut ID (info)}}', 'info', '{{Code statut numérique de l\'API Wallbox : sert à savoir si une voiture est branchée et si la charge est finie.}}'),
    'energy' => array('{{Énergie de la session (info, kWh)}}', 'info', '{{Optionnel : affine le % de batterie entre deux mises à jour du constructeur.}}'),
);
?>
<form class="form-horizontal">
  <fieldset>
    <legend><i class="fas fa-flask"></i> {{Sécurité}}</legend>
    <div class="form-group">
      <label class="col-md-4 control-label">{{Mode simulation}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Activé : le plugin calcule et affiche ses décisions, mais n'envoie aucune commande aux bornes. Désactivez-le quand les décisions affichées vous conviennent.}}"></i></sup>
      </label>
      <div class="col-md-4">
        <input type="checkbox" class="configKey" data-l1key="simulation">
      </div>
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
      <label class="col-md-4 control-label">{{Heure de démarrage du boost}}
        <sup><i class="fas fa-question-circle tooltips" title="{{Heure à laquelle un boost programmé démarre le jour creux suivant.}}"></i></sup>
      </label>
      <div class="col-md-2">
        <input type="time" class="configKey form-control" data-l1key="boost_time">
      </div>
    </div>

    <?php foreach (array(1, 2) as $b) { ?>
      <legend><i class="fas fa-charging-station"></i> {{Borne}} <?php echo $b; ?></legend>
      <div class="form-group">
        <label class="col-md-4 control-label">{{Nom}}</label>
        <div class="col-md-4">
          <input class="configKey form-control" data-l1key="borne<?php echo $b; ?>_name" placeholder="Wallbox 0<?php echo $b; ?>">
        </div>
      </div>
      <?php foreach ($borneFields as $key => $field) { ?>
        <div class="form-group">
          <label class="col-md-4 control-label"><?php echo $field[0]; ?>
            <?php if ($field[2] !== '') { ?><sup><i class="fas fa-question-circle tooltips" title="<?php echo $field[2]; ?>"></i></sup><?php } ?>
          </label>
          <div class="col-md-6">
            <div class="input-group">
              <input class="configKey form-control roundedLeft" data-l1key="borne<?php echo $b; ?>_<?php echo $key; ?>">
              <span class="input-group-btn">
                <a class="btn btn-default roundedRight chargePlannerSelectCmd" data-type="<?php echo $field[1]; ?>"><i class="fas fa-list-alt"></i></a>
              </span>
            </div>
          </div>
        </div>
      <?php } ?>
    <?php } ?>
  </fieldset>
</form>
<script>
  document.querySelectorAll('.chargePlannerSelectCmd').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = btn.closest('.input-group').querySelector('input');
      jeedom.cmd.getSelectModal({ cmd: { type: btn.getAttribute('data-type') } }, function (result) {
        input.value = result.human;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      });
    });
  });
</script>
