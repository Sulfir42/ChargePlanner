<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('ChargePlanner');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
require_once __DIR__ . '/../../core/class/ChargePlanner.class.php';
$simulation = ChargePlanner::isSimulation();
$days = ChargePlannerFormat::DAYS;
?>
<div class="row row-overflow">
  <div class="col-xs-12 eqLogicThumbnailDisplay">
    <legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
    <div class="eqLogicThumbnailContainer">
      <div class="cursor eqLogicAction logoPrimary" data-action="add">
        <i class="fas fa-plus-circle"></i><br><span>{{Ajouter une voiture}}</span>
      </div>
      <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
        <i class="fas fa-wrench"></i><br><span>{{Configuration}}</span>
      </div>
    </div>
    <?php if ($simulation) { ?>
      <div class="alert alert-warning"><i class="fas fa-flask"></i> {{Mode simulation actif : les décisions sont calculées et affichées, mais aucune commande n'est envoyée aux bornes. Désactivez-le dans la configuration du plugin.}}</div>
    <?php } ?>
    <legend><i class="fas fa-car"></i> {{Mes voitures}}</legend>
    <?php if (count($eqLogics) == 0) { ?>
      <div class="text-center" style="font-size:1.2em;font-weight:bold;">{{Aucune voiture. Cliquez sur « Ajouter une voiture ».}}</div>
    <?php } else { ?>
      <div class="input-group" style="margin:5px;">
        <input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">
        <div class="input-group-btn">
          <a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>
          <a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>
        </div>
      </div>
      <div class="eqLogicThumbnailContainer">
        <?php
        foreach ($eqLogics as $eqLogic) {
            $opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
            echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
            echo '<img src="' . $plugin->getPathImgIcon() . '">';
            echo '<br><span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
            echo '<span class="hiddenAsCard displayTableRight hidden">';
            echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
            echo '</span></div>';
        }
        ?>
      </div>
    <?php } ?>
  </div>

  <div class="col-xs-12 eqLogic" style="display:none;">
    <div class="input-group pull-right" style="display:inline-flex;">
      <span class="input-group-btn">
        <a class="btn btn-sm btn-default eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
        <a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
        <a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
      </span>
    </div>
    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
      <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-car"></i> {{Voiture}}</a></li>
      <li role="presentation"><a href="#objectivetab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-bullseye"></i> {{Objectifs}}</a></li>
      <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list"></i> {{Commandes}}</a></li>
    </ul>
    <div class="tab-content">

      <div role="tabpanel" class="tab-pane active" id="eqlogictab">
        <br>
        <form class="form-horizontal">
          <fieldset>
            <div class="col-lg-6">
              <legend><i class="fas fa-wrench"></i> {{Général}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Nom de la voiture}}</label>
                <div class="col-sm-6">
                  <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
                  <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Ex. E208}}">
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Objet parent}}</label>
                <div class="col-sm-6">
                  <select class="eqLogicAttr form-control" data-l1key="object_id">
                    <option value="">{{Aucun}}</option>
                    <?php
                    foreach (jeeObject::buildTree(null, false) as $object) {
                        echo '<option value="' . $object->getId() . '">' . str_repeat('&nbsp;&nbsp;', $object->getConfiguration('parentNumber')) . $object->getName() . '</option>';
                    }
                    ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Options}}</label>
                <div class="col-sm-6">
                  <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>{{Activer}}</label>
                  <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>{{Visible}}</label>
                </div>
              </div>

              <legend><i class="fas fa-charging-station"></i> {{Borne}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Borne habituelle}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Pour un jour où les voitures sont inversées, utilisez la commande « Choisir la borne » : elle revient d'elle-même sur la borne habituelle 30 min après le débranchement.}}"></i></sup>
                </label>
                <div class="col-sm-6">
                  <select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="default_borne">
                    <?php foreach (ChargePlanner::BORNES as $b) { ?>
                      <option value="<?php echo $b; ?>"><?php echo htmlspecialchars(ChargePlanner::borneName($b), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php } ?>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Puissance de charge (W)}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Puissance réelle en mode Normal, utilisée pour calculer l'heure de départ (ex. 7200 en monophasé 32 A).}}"></i></sup>
                </label>
                <div class="col-sm-3">
                  <input type="number" min="1000" max="22000" step="100" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="power_w">
                </div>
              </div>
            </div>

            <div class="col-lg-6">
              <legend><i class="fas fa-car-battery"></i> {{Batterie}}</legend>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Niveau de batterie (info, %)}}</label>
                <div class="col-sm-7">
                  <div class="input-group">
                    <input class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="soc_cmd" placeholder="#[Garage][E208 GT][Batterie]#">
                    <span class="input-group-btn"><a class="btn btn-default roundedRight chargePlannerSelectCmd" data-type="info"><i class="fas fa-list-alt"></i></a></span>
                  </div>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Branchée (info, optionnel)}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Utilisé seulement si le statut de la borne n'est pas configuré.}}"></i></sup>
                </label>
                <div class="col-sm-7">
                  <div class="input-group">
                    <input class="eqLogicAttr form-control roundedLeft" data-l1key="configuration" data-l2key="plugged_cmd">
                    <span class="input-group-btn"><a class="btn btn-default roundedRight chargePlannerSelectCmd" data-type="info"><i class="fas fa-list-alt"></i></a></span>
                  </div>
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Capacité utile (kWh)}}</label>
                <div class="col-sm-3">
                  <input type="number" min="5" max="200" step="0.1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="capacity_kwh">
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Rendement de charge (%)}}
                  <sup><i class="fas fa-question-circle tooltips" title="{{Part de l'énergie tirée du réseau qui arrive dans la batterie. 90 % est une bonne valeur par défaut.}}"></i></sup>
                </label>
                <div class="col-sm-3">
                  <input type="number" min="50" max="100" step="1" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="efficiency">
                </div>
              </div>
              <div class="form-group">
                <label class="col-sm-4 control-label">{{Marge de sécurité (min)}}</label>
                <div class="col-sm-3">
                  <input type="number" min="0" max="240" step="5" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="margin_min">
                </div>
              </div>
            </div>
          </fieldset>
        </form>
      </div>

      <div role="tabpanel" class="tab-pane" id="objectivetab">
        <br>
        <div class="alert alert-info">
          {{Pour chaque objectif, la voiture doit avoir au moins ce niveau de batterie à l'heure indiquée. Tant que ce n'est pas nécessaire, la borne reste en Eco pour profiter du surplus solaire ; le plugin la passe en Normal juste assez tôt, puis la remet en Eco une fois l'objectif atteint.}}
        </div>
        <form class="form-horizontal">
          <div class="form-group">
            <label class="col-sm-3 control-label">{{Compléter de préférence en jour creux}}
              <sup><i class="fas fa-question-circle tooltips" title="{{Si un jour plein précède l'échéance (ex. jeudi avant un objectif vendredi 0h), la charge manquante est faite pendant le dernier jour creux (mercredi soir) plutôt qu'au dernier moment en heure pleine.}}"></i></sup>
            </label>
            <div class="col-sm-3">
              <input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="prefer_offpeak">
            </div>
          </div>
        </form>
        <table class="table table-bordered table-condensed" style="max-width:720px;">
          <thead>
            <tr><th style="width:80px;">{{Actif}}</th><th>{{Jour}}</th><th>{{Heure}}</th><th>{{Batterie minimum}}</th></tr>
          </thead>
          <tbody>
            <?php for ($i = 1; $i <= ChargePlanner::OBJECTIVE_SLOTS; $i++) { ?>
              <tr>
                <td class="text-center"><input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="obj<?php echo $i; ?>_enabled"></td>
                <td>
                  <select class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="obj<?php echo $i; ?>_day">
                    <?php foreach ($days as $n => $label) { ?><option value="<?php echo $n; ?>"><?php echo $label; ?></option><?php } ?>
                  </select>
                </td>
                <td><input type="time" class="eqLogicAttr form-control input-sm" data-l1key="configuration" data-l2key="obj<?php echo $i; ?>_time"></td>
                <td>
                  <div class="input-group input-group-sm" style="max-width:140px;">
                    <input type="number" min="0" max="100" step="5" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="obj<?php echo $i; ?>_target">
                    <span class="input-group-addon">%</span>
                  </div>
                </td>
              </tr>
            <?php } ?>
          </tbody>
        </table>
      </div>

      <div role="tabpanel" class="tab-pane" id="commandtab">
        <br>
        <table id="table_cmd" class="table table-bordered table-condensed">
          <thead>
            <tr>
              <th class="hidden-xs" style="min-width:50px;width:70px;">ID</th>
              <th style="min-width:200px;width:300px;">{{Nom}}</th>
              <th style="width:150px;">{{Type}}</th>
              <th style="min-width:200px;">{{Options}}</th>
              <th style="min-width:160px;">{{Etat}}</th>
              <th style="width:160px;">{{Actions}}</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include_file('desktop', 'ChargePlanner', 'js', 'ChargePlanner'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
