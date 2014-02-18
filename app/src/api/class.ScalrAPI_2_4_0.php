<?php

use Scalr\Acl\Acl;

class ScalrAPI_2_4_0 extends ScalrAPI_2_3_0
{
    function getFarmByName($FarmName) {
        $options = array($this->Environment->id, $FarmName);
        $stmt = "SELECT id, name, status, comments FROM farms WHERE env_id = ? AND name = ?";
        if (!$this->isAllowed(Acl::RESOURCE_FARMS, Acl::PERM_FARMS_NOT_OWNED_FARMS)) {
            //Filters not owned farms
            $stmt .= " AND created_by_id = ? ";
            array_push($options, $this->user->getId());
        }
        
        $farms = $this->DB->Execute($stmt, $options);
        $farmRecord = $farms->FetchRow();
        if ($farmRecord == NULL)
            return null;
        
        return DBFarm::LoadByID($farmRecord['id']);
    }
    
    public function FarmCreate($FarmName, $RolesLaunchOrder = 'Simultaneous', $Description = '') {
        $response = $this->CreateInitialResponse();
        
        $this->restrictAccess(Acl::RESOURCE_FARMS, Acl::PERM_FARMS_MANAGE);
        $this->user->getAccount()->validateLimit(Scalr_Limits::ACCOUNT_FARMS, 1);
        
        if (empty($FarmName))
            throw new Exception("Farm name required");
                
        $dbFarm = DBFarm::create($FarmName, $this->user, $this->Environment->id);
     
        // Default launch older is simultaneous
        $dbFarm->RolesLaunchOrder = ($RolesLaunchOrder == 'Sequential') ? 1 : 0;
        $dbFarm->save();
        
        $response->Result = "OK";
        return $response;
    }
    
    public function FarmListParameters() {
        $response = $this->CreateInitialResponse();
        $refl = new ReflectionClass('DBFarm');        
        $response->Result = array_keys($refl->getConstants());
        return $response;
    }
    
    public function FarmUpdateParameter($FarmName, $ParamName, $ParamValue) {
        $response = $this->CreateInitialResponse();
        
        $this->restrictAccess(Acl::RESOURCE_FARMS, Acl::PERM_FARMS_MANAGE);
        $this->user->getAccount()->validateLimit(Scalr_Limits::ACCOUNT_FARMS, 1);
        
        $farm = $this->getFarmByName($FarmName);
        if($farm === NULL)
            throw new Exception("Requested farm doesn't exists");
        
        $refl = new ReflectionClass('DBFarm');
        $constants = $refl->getConstants();
                
        $farm->SetSetting($constants[$ParamName], $ParamValue);
                
        $response->Result = "OK";
        return $response;
    }
    
    public function FarmRoleListParameters() {
        $response = $this->CreateInitialResponse();
        $refl = new ReflectionClass('DBFarmRole');        
        $response->Result = array_keys($refl->getConstants());
        return $response;
    }
    
    public function FarmAddRole($FarmName, $Platform, $RoleName, $FarmRoleName, $CloudLocation) {
        $response = $this->CreateInitialResponse();
        
        $this->restrictAccess(Acl::RESOURCE_FARMS, Acl::PERM_FARMS_MANAGE);
        $this->user->getAccount()->validateLimit(Scalr_Limits::ACCOUNT_FARMS, 1);
        
        $dbFarm = $this->getFarmByName($FarmName);
        if($dbFarm === NULL)
            throw new Exception("Requested farm doesn't exists");
        
        $dbRole = DBRole::loadByFilter(array("name" => $RoleName));
        if($dbRole === NULL)
            throw new Exception("Requested role doesn't exists");
        
        if(array_search($Platform, $dbRole->getPlatforms()) === false)
            throw new Exception("Requested role isn't available for specified platform");

        if(array_search($CloudLocation, $dbRole->getCloudLocations()) === false)
            throw new Exception("Requested role isn't available for specified cloud location");
        
        $dbFarmRole = $dbFarm->AddRole($dbRole, $Platform, $CloudLocation, count($dbFarm->GetFarmRoles())-1, $FarmRoleName);
        $response->Result = "OK";
        return $response;
    }
    
    public function FarmRoleUpdateParameters($FarmName, $FarmRoleName, $Platform, $RoleParameters) {
        $response = $this->CreateInitialResponse();
        
        $dbFarm = $this->getFarmByName($FarmName);
        if($dbFarm === NULL)
            throw new Exception("Requested farm doesn't exists");
        
        $dbFarmRole = NULL;
        foreach($dbFarm->GetFarmRoles() as $tmpFarmRole) {
            if($tmpFarmRole->Alias == $FarmRoleName) {
                $dbFarmRole = $tmpFarmRole;
                break;
            }
        }
                
        if ($dbFarmRole == NULL)
            throw new Exception("Requested farm role doesn't exists");
        
        $oldRoleSettings = $dbFarmRole->GetAllSettings();
        $refl = new ReflectionClass('DBFarmRole');
        $constants = $refl->getConstants();
        
        $newRoleSettings = [];
        foreach($RoleParameters as $paramName => $paramValue) {
            if($constants[$paramName] == NULL)
                throw new Exception("Parameter $paramName is unknown");
            $newRoleSettings[$constants[$paramName]] = $paramValue;
        }
        
        # save values to database
        foreach($newRoleSettings as $paramName => $paramValue) {
            $dbFarmRole->SetSetting($paramName, $paramValue, DBFarmRole::TYPE_CFG);
        }
    
        # propagate changes to running farms
        switch($Platform) {
            case SERVER_PLATFORMS::EC2:
                Modules_Platforms_Ec2_Helpers_Ebs::farmUpdateRoleSettings($dbFarmRole, $oldRoleSettings, $newRoleSettings);
                Modules_Platforms_Ec2_Helpers_Eip::farmUpdateRoleSettings($dbFarmRole, $oldRoleSettings, $newRoleSettings);
                Modules_Platforms_Ec2_Helpers_Elb::farmUpdateRoleSettings($dbFarmRole, $oldRoleSettings, $newRoleSettings);
                #Modules_Platforms_Ec2_Helpers_Ec2::farmSave($dbFarm, array($dbFarmRole));
                break;
        }
        
        $response->Result = "OK";
        return $response;
    }
    
    public function FarmRoleSetScripts($FarmName, $FarmRoleName, $Scripts) {
        $response = $this->CreateInitialResponse();
        
        $dbFarm = $this->getFarmByName($FarmName);
        if($dbFarm === NULL)
            throw new Exception("Requested farm doesn't exists");
        
        $dbFarmRole = NULL;
        foreach($dbFarm->GetFarmRoles() as $tmpFarmRole) {
            if($tmpFarmRole->Alias == $FarmRoleName) {
                $dbFarmRole = $tmpFarmRole;
                break;
            }
        }
                
        if ($dbFarmRole == NULL)
            throw new Exception("Requested farm role doesn't exists");
        
        $dbFarmRole->SetScripts(json_decode($Scripts,true));
        $dbFarmRole->save();
        
        $response->Result = "OK";
        return $response;
    }
    
    public function FarmGetDetails($FarmID)
    {
        $response = parent::FarmGetDetails($FarmID);
        foreach ($response->FarmRoleSet->Item as &$item) {
            $dbFarmRole = DBFarmRole::LoadByID($item->ID);
            $item->Alias = $dbFarmRole->Alias;
        }
        return $response;
    }
}

?>
