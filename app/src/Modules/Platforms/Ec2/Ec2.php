<?php

use Scalr\Service\Aws\S3\DataType\ObjectData;
use Scalr\Service\Aws\Client\ClientException;
use Scalr\Service\Aws\DataType\ErrorData;
use Scalr\Service\Aws\Ec2\DataType\SecurityGroupFilterNameType;
use Scalr\Service\Aws\Ec2\DataType\IpPermissionData;
use Scalr\Service\Aws\Ec2\DataType\IpRangeList;
use Scalr\Service\Aws\Ec2\DataType\IpRangeData;
use Scalr\Service\Aws\Ec2\DataType\UserIdGroupPairList;
use Scalr\Service\Aws\Ec2\DataType\UserIdGroupPairData;
use Scalr\Service\Aws\Ec2\DataType\CreateImageRequestData;
use Scalr\Service\Aws\Ec2\DataType\RunInstancesRequestData;
use Scalr\Service\Aws\Ec2\DataType\BlockDeviceMappingData;
use Scalr\Service\Aws\Ec2\DataType\PlacementResponseData;
use Scalr\Service\Aws\Ec2\DataType\InstanceNetworkInterfaceSetRequestData;
use Scalr\Service\Aws\Ec2\DataType\SubnetFilterNameType;
use Scalr\Service\Aws\Ec2\DataType\InternetGatewayFilterNameType;
use Scalr\Service\Aws\Ec2\DataType\RouteTableFilterNameType;
use Scalr\Service\Aws\Ec2\DataType\VpcData;

class Modules_Platforms_Ec2 extends Modules_Platforms_Aws implements IPlatformModule
{

    /** Properties **/
    const ACCOUNT_ID 	= 'ec2.account_id';
    const ACCESS_KEY	= 'ec2.access_key';
    const SECRET_KEY	= 'ec2.secret_key';
    const PRIVATE_KEY	= 'ec2.private_key';
    const CERTIFICATE	= 'ec2.certificate';

    const DEFAULT_VPC_ID = 'ec2.vpc.default';

    /**
     * @var array
     */
    public $instancesListCache = array();

    public function __construct()
    {
        parent::__construct();
    }


    public function getPropsList()
    {
        return array(
            self::ACCOUNT_ID	=> 'AWS Account ID',
            self::ACCESS_KEY	=> 'AWS Access Key',
            self::SECRET_KEY	=> 'AWS Secret Key',
            self::CERTIFICATE	=> 'AWS x.509 Certificate',
            self::PRIVATE_KEY	=> 'AWS x.509 Private Key'
        );
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::GetServerCloudLocation()
     */
    public function GetServerCloudLocation(DBServer $DBServer)
    {
        return $DBServer->GetProperty(EC2_SERVER_PROPERTIES::REGION);
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::GetServerID()
     */
    public function GetServerID(DBServer $DBServer)
    {
        return $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID);
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::GetServerFlavor()
     */
    public function GetServerFlavor(DBServer $DBServer)
    {
        return $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_TYPE);
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::IsServerExists()
     */
    public function IsServerExists(DBServer $DBServer, $debug = false)
    {
        $list = $this->GetServersList(
            $DBServer->GetEnvironmentObject(),
            $DBServer->GetProperty(EC2_SERVER_PROPERTIES::REGION)
        );
        return !is_array($list) ? false :
            in_array($DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID), array_keys($list));
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::GetServerIPAddresses()
     */
    public function GetServerIPAddresses(DBServer $DBServer)
    {
        $instance = $DBServer->GetEnvironmentObject()->aws($DBServer)
                             ->ec2->instance->describe($DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID))
                             ->get(0)->instancesSet->get(0);

        return array(
            'localIp'  => $instance->privateIpAddress,
            'remoteIp' => $instance->ipAddress
        );
    }

    /**
     * Gets the list of the EC2 instances
     * for the specified environment and AWS location
     *
     * @param   Scalr_Environment $environment Environment Object
     * @param   string            $region      EC2 location name
     * @param   bool              $skipCache   Whether it should skip the cache.
     * @return  array Returns array looks like array(InstanceId => stateName)
     */
    public function GetServersList(Scalr_Environment $environment, $region, $skipCache = false)
    {
        if (!$region) {
            return array();
        }
        if (empty($this->instancesListCache[$environment->id][$region]) || $skipCache) {
            try {
                $results = $environment->aws($region)->ec2->instance->describe();
            } catch (Exception $e) {
                throw new Exception(sprintf("Cannot get list of servers for platfrom ec2: %s", $e->getMessage()));
            }
            if (count($results)) {
                foreach ($results as $reservation) {
                    /* @var $reservation Scalr\Service\Aws\Ec2\DataType\ReservationData */
                    foreach ($reservation->instancesSet as $instance) {
                        /* @var $instance Scalr\Service\Aws\Ec2\DataType\InstanceData */
                        $this->instancesListCache[$environment->id][$region][$instance->instanceId] =
                            $instance->instanceState->name;
                    }
                }
            }
        }

        return !empty($this->instancesListCache[$environment->id][$region]) ?
            $this->instancesListCache[$environment->id][$region] : array();
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::GetServerRealStatus()
     */
    public function GetServerRealStatus(DBServer $DBServer)
    {
        $region = $DBServer->GetProperty(EC2_SERVER_PROPERTIES::REGION);
        $iid = $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID);

        if (!$iid || !$region) {
            $status = 'not-found';
        } elseif (empty($this->instancesListCache[$DBServer->GetEnvironmentObject()->id][$region][$iid])) {
            $aws = $DBServer->GetEnvironmentObject()->aws($region);

            try {
                $reservations = $aws->ec2->instance->describe($iid);

                if ($reservations && count($reservations) > 0 && $reservations->get(0)->instancesSet &&
                    count($reservations->get(0)->instancesSet) > 0) {
                    $status = $reservations->get(0)->instancesSet->get(0)->instanceState->name;
                } else {
                    $status = 'not-found';
                }

            } catch (Exception $e) {
                if (stristr($e->getMessage(), "does not exist")) {
                    $status = 'not-found';
                } else {
                    throw $e;
                }
            }
        } else {
            $status = $this->instancesListCache[$DBServer->GetEnvironmentObject()->id][$region][$iid];
        }

        return Modules_Platforms_Ec2_Adapters_Status::load($status);
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::TerminateServer()
     */
    public function TerminateServer(DBServer $DBServer)
    {
        $aws = $DBServer->GetEnvironmentObject()->aws($DBServer);
        $aws->ec2->instance->terminate($DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID));

        return true;
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::RebootServer()
     */
    public function RebootServer(DBServer $DBServer)
    {
        $aws = $DBServer->GetEnvironmentObject()->aws($DBServer);
        $aws->ec2->instance->reboot($DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID));

        return true;
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::RemoveServerSnapshot()
     */
    public function RemoveServerSnapshot(DBRole $DBRole)
    {
        foreach ($DBRole->getImageId(SERVER_PLATFORMS::EC2) as $location => $imageId) {
            try {
                $aws = $DBRole->getEnvironmentObject()->aws($location);
                try {
                    $ami = $aws->ec2->image->describe($imageId)->get(0);
                } catch (Exception $e) {
                    if (stristr($e->getMessage(), "Failure Signing Data") ||
                        stristr($e->getMessage(), "is no longer available") ||
                        stristr($e->getMessage(), "does not exist") ||
                        stristr($e->getMessage(), "Not authorized for image")) {

                        return true;
                    } else {

                        throw $e;
                    }
                }

                //$ami variable is expected to be defined here

                $platfrom = $ami->platform;
                $rootDeviceType = $ami->rootDeviceType;

                if ($rootDeviceType == 'ebs') {
                    $ami->deregister();

                    //blockDeviceMapping is not mandatory option in the response as well as ebs data set.
                    $snapshotId = $ami->blockDeviceMapping && count($ami->blockDeviceMapping) > 0 &&
                                  $ami->blockDeviceMapping->get(0)->ebs ?
                                  $ami->blockDeviceMapping->get(0)->ebs->snapshotId : null;

                    if ($snapshotId) {
                        $aws->ec2->snapshot->delete($snapshotId);
                    }
                } else {
                    $image_path = $ami->imageLocation;
                    $chunks = explode("/", $image_path);

                    $bucketName = array_shift($chunks);
                    $manifestObjectName = implode('/', $chunks);

                    $prefix = str_replace(".manifest.xml", "", $manifestObjectName);

                    try {
                        $bucket_not_exists = false;
                        $objects = $aws->s3->bucket->listObjects($bucketName, null, null, null, $prefix);
                    } catch (\Exception $e) {
                        if ($e instanceof ClientException &&
                            $e->getErrorData() instanceof ErrorData &&
                            $e->getErrorData()->getCode() == 404) {
                            $bucket_not_exists = true;
                        }
                    }

                    if ($ami) {
                        if (!$bucket_not_exists) {
                            /* @var $object ObjectData */
                            foreach ($objects as $object) {
                                $object->delete();
                            }
                            $bucket_not_exists = true;
                        }

                        if ($bucket_not_exists) {
                            $aws->ec2->image->deregister($imageId);
                        }
                    }
                }

                unset($aws);
                unset($ami);

            } catch (Exception $e) {
                if (stristr($e->getMessage(), "is no longer available") ||
                    stristr($e->getMessage(), "Not authorized for image")) {
                    continue;
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::CheckServerSnapshotStatus()
     */
    public function CheckServerSnapshotStatus(BundleTask $BundleTask)
    {
        if ($BundleTask->bundleType == SERVER_SNAPSHOT_CREATION_TYPE::EC2_WIN2003) {

        } else if (in_array($BundleTask->bundleType,
                   array(SERVER_SNAPSHOT_CREATION_TYPE::EC2_EBS_HVM, SERVER_SNAPSHOT_CREATION_TYPE::EC2_WIN200X))) {
            try {
                $DBServer = DBServer::LoadByID($BundleTask->serverId);

                $aws = $DBServer->GetEnvironmentObject()->aws($DBServer);

                $ami = $aws->ec2->image->describe($BundleTask->snapshotId)->get(0);

                $BundleTask->Log(sprintf("Checking snapshot creation status: %s", $ami->imageState));

                $metaData = $BundleTask->getSnapshotDetails();
                if ($ami->imageState == 'available') {

                    $metaData['szr_version'] = $DBServer->GetProperty(SERVER_PROPERTIES::SZR_VESION);

                    if ($ami->rootDeviceType == 'ebs') {
                        $tags[] = ROLE_TAGS::EC2_EBS;
                    }

                    if ($ami->virtualizationType == 'hvm') {
                        $tags[] = ROLE_TAGS::EC2_HVM;
                    }

                    $metaData['tags'] = $tags;

                    $BundleTask->SnapshotCreationComplete($BundleTask->snapshotId, $metaData);
                } else {
                    $BundleTask->Log("CheckServerSnapshotStatus: AMI status = {$ami->imageState}. Waiting...");
                }
            } catch (Exception $e) {
                Logger::getLogger(__CLASS__)->fatal("CheckServerSnapshotStatus ({$BundleTask->id}): {$e->getMessage()}");
            }
        }
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::CreateServerSnapshot()
     */
    public function CreateServerSnapshot(BundleTask $BundleTask)
    {
        $DBServer = DBServer::LoadByID($BundleTask->serverId);

        $aws = $DBServer->GetEnvironmentObject()->aws($DBServer);

        if (!$BundleTask->prototypeRoleId) {

            $proto_image_id = $DBServer->GetProperty(EC2_SERVER_PROPERTIES::AMIID);

        } else {
            $protoRole = DBRole::loadById($BundleTask->prototypeRoleId);

            $proto_image_id = $protoRole->getImageId(
                SERVER_PLATFORMS::EC2,
                $DBServer->GetProperty(EC2_SERVER_PROPERTIES::REGION)
            );

            $details = $protoRole->getImageDetails(
                SERVER_PLATFORMS::EC2,
                $DBServer->GetProperty(EC2_SERVER_PROPERTIES::REGION)
            );

            if (in_array($details['os_family'], array('oel', 'redhat', 'scientific'))) {
                $BundleTask->bundleType = SERVER_SNAPSHOT_CREATION_TYPE::EC2_EBS_HVM;
            }
        }

        $ami = $aws->ec2->image->describe($proto_image_id)->get(0);
        $platfrom = $ami->platform;

        if ($platfrom == 'windows') {
            if ($ami->rootDeviceType != 'ebs') {
                $BundleTask->SnapshotCreationFailed("Only EBS root filesystem supported for Windows servers.");

                return;
            }
            if ($BundleTask->status == SERVER_SNAPSHOT_CREATION_STATUS::PENDING) {
                $BundleTask->bundleType = SERVER_SNAPSHOT_CREATION_TYPE::EC2_WIN200X;
                $BundleTask->Log(sprintf(_("Selected platfrom snapshoting type: %s"), $BundleTask->bundleType));
                $BundleTask->status = SERVER_SNAPSHOT_CREATION_STATUS::PREPARING;
                try {
                    $msg = $DBServer->SendMessage(new Scalr_Messaging_Msg_Win_PrepareBundle($BundleTask->id));
                    if ($msg) {
                        $BundleTask->Log(sprintf(
                            _("PrepareBundle message sent. MessageID: %s. Bundle task status changed to: %s"),
                            $msg->messageId, $BundleTask->status
                        ));
                    } else {
                        throw new Exception("Cannot send message");
                    }
                } catch (Exception $e) {
                    $BundleTask->SnapshotCreationFailed("Cannot send PrepareBundle message to server.");

                    return false;
                }
            } elseif ($BundleTask->status == SERVER_SNAPSHOT_CREATION_STATUS::PREPARING) {
                $BundleTask->Log(sprintf(_("Selected platform snapshot type: %s"), $BundleTask->bundleType));
                try {
                    $request = new CreateImageRequestData(
                        $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID),
                        $BundleTask->roleName . "-" . date("YmdHi")
                    );
                    $request->description = $BundleTask->roleName;
                    $request->noReboot = false;

                    $imageId = $aws->ec2->image->create($request);

                    $BundleTask->status = SERVER_SNAPSHOT_CREATION_STATUS::IN_PROGRESS;
                    $BundleTask->snapshotId = $imageId;
                    $BundleTask->Log(sprintf(
                        _("Snapshot creating initialized (AMIID: %s). Bundle task status changed to: %s"),
                        $BundleTask->snapshotId, $BundleTask->status
                    ));
                } catch (Exception $e) {
                    $BundleTask->SnapshotCreationFailed($e->getMessage());

                    return;
                }
            }
        } else {
            $BundleTask->status = SERVER_SNAPSHOT_CREATION_STATUS::IN_PROGRESS;
            if (!$BundleTask->bundleType) {
                if ($ami->rootDeviceType == 'ebs') {
                    if ($ami->virtualizationType == 'hvm') {
                        $BundleTask->bundleType = SERVER_SNAPSHOT_CREATION_TYPE::EC2_EBS_HVM;
                    } else {
                        $BundleTask->bundleType = SERVER_SNAPSHOT_CREATION_TYPE::EC2_EBS;
                    }
                } else {
                    $BundleTask->bundleType = SERVER_SNAPSHOT_CREATION_TYPE::EC2_S3I;
                }
            }
            $BundleTask->Save();
            $BundleTask->Log(sprintf(_("Selected platfrom snapshoting type: %s"), $BundleTask->bundleType));
            if ($BundleTask->bundleType == SERVER_SNAPSHOT_CREATION_TYPE::EC2_EBS_HVM) {
                try {
                    $request = new CreateImageRequestData(
                        $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID),
                        $BundleTask->roleName . "-" . date("YmdHi")
                    );
                    $request->description = $BundleTask->roleName;
                    $request->noReboot = false;

                    $imageId = $aws->ec2->image->create($request);

                    $BundleTask->status = SERVER_SNAPSHOT_CREATION_STATUS::IN_PROGRESS;
                    $BundleTask->snapshotId = $imageId;
                    $BundleTask->Log(sprintf(
                        _("Snapshot creating initialized (AMIID: %s). Bundle task status changed to: %s"),
                        $BundleTask->snapshotId, $BundleTask->status
                    ));
                } catch (Exception $e) {
                    $BundleTask->SnapshotCreationFailed($e->getMessage());

                    return;
                }
            } else {
                $msg = new Scalr_Messaging_Msg_Rebundle($BundleTask->id, $BundleTask->roleName, array());
                $metaData = $BundleTask->getSnapshotDetails();
                if ($metaData['rootVolumeSize']) {
                    $msg->volumeSize = $metaData['rootVolumeSize'];
                }
                if (!$DBServer->SendMessage($msg)) {
                    $BundleTask->SnapshotCreationFailed(
                        "Cannot send rebundle message to server. Please check event log for more details."
                    );

                    return;
                } else {
                    $BundleTask->Log(sprintf(
                        _("Snapshot creation started (MessageID: %s). Bundle task status changed to: %s"),
                        $msg->messageId, $BundleTask->status
                    ));
                }
            }
        }
        $BundleTask->setDate('started');
        $BundleTask->Save();
    }

    private function ApplyAccessData(Scalr_Messaging_Msg $msg)
    {
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::GetServerConsoleOutput()
     */
    public function GetServerConsoleOutput(DBServer $DBServer)
    {
        $aws = $DBServer->GetEnvironmentObject()->aws($DBServer);
        $c = $aws->ec2->instance->getConsoleOutput($DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID));

        if ($c->output) {
            $ret = $c->output;
        } else {
            $ret = false;
        }
        return $ret;
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::GetServerExtendedInformation()
     */
    public function GetServerExtendedInformation(DBServer $DBServer)
    {
        try {
            $aws = $DBServer->GetEnvironmentObject()->aws($DBServer);

            $iid = $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID);
            if (!$iid)
                return false;

            $iinfo = $aws->ec2->instance->describe($iid)->get(0);

            if (isset($iinfo->instancesSet)) {

                $instanceData = $iinfo->instancesSet->get(0);

                if (isset($iinfo->groupSet[0]->groupId)) {
                    $infoGroups = $iinfo->groupSet;
                } elseif (isset($iinfo->instancesSet[0]->groupSet[0]->groupId)) {
                    $infoGroups = $instanceData->groupSet;
                } else {
                    $infoGroups = array();
                }

                $groups = array();
                foreach ($infoGroups as $sg) {
                    /* @var $sg \Scalr\Service\Aws\Ec2\DataType\SecurityGroupData */
                    $groups[] = $sg->groupName
                      . " (<a href='#/security/groups/" . $sg->groupId . "/edit"
                      . "?cloudLocation=" . $DBServer->GetProperty(EC2_SERVER_PROPERTIES::REGION)
                      . "&platform=ec2'>" . $sg->groupId . "</a>)";
                }

                //monitoring isn't mandatory data set in the InstanceData
                $monitoring = isset($instanceData->monitoring->state) ?
                    $instanceData->monitoring->state : null;

                if ($monitoring == 'disabled')
                    $monitoring = "Disabled";
                else
                    $monitoring = "Enabled";

                try {
                    $statusInfo = $aws->ec2->instance->describeStatus(
                        $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID)
                    )->get(0);
                } catch (Exception $e) {
                }

                if (!empty($statusInfo)) {

                    if ($statusInfo->systemStatus->status == 'ok') {
                        $systemStatus = '<span style="color:green;">OK</span>';
                    } else {
                        $txtDetails = "";
                        if (!empty($statusInfo->systemStatus->details)) {
                            foreach ($statusInfo->systemStatus->details as $d) {
                                /* @var $d \Scalr\Service\Aws\Ec2\DataType\InstanceStatusDetailsSetData */
                                $txtDetails .= " {$d->name} is {$d->status},";
                            }
                        }
                        $txtDetails = trim($txtDetails, " ,");
                        $systemStatus = "<span style='color:red;'>"
                                      . $statusInfo->systemStatus->status
                                      . "</span> ({$txtDetails})";
                    }

                    if ($statusInfo->instanceStatus->status == 'ok') {
                        $iStatus = '<span style="color:green;">OK</span>';
                    } else {
                        $txtDetails = "";
                        foreach ($statusInfo->instanceStatus->details as $d) {
                            $txtDetails .= " {$d->name} is {$d->status},";
                        }
                        $txtDetails = trim($txtDetails, " ,");
                        $iStatus = "<span style='color:red;'>"
                                 . $statusInfo->instanceStatus->status
                                 . "</span> ({$txtDetails})";
                    }

                } else {
                    $systemStatus = "Unknown";
                    $iStatus = "Unknown";
                }

                $retval = array(
                    'AWS System Status'       => $systemStatus,
                    'AWS Instance Status'     => $iStatus,
                    'Cloud Server ID'         => $DBServer->GetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID),
                    'Owner ID'                => $iinfo->ownerId,
                    'Image ID (AMI)'          => $instanceData->imageId,
                    'Public DNS name'         => $instanceData->dnsName,
                    'Private DNS name'        => $instanceData->privateDnsName,
                    'Public IP'               => $instanceData->ipAddress,
                    'Private IP'              => $instanceData->privateIpAddress,
                    'Key name'                => $instanceData->keyName,
                    //'AMI launch index'        => $instanceData->amiLaunchIndex,
                    'Instance type'           => $instanceData->instanceType,
                    'Launch time'             => $instanceData->launchTime->format('Y-m-d\TH:i:s.000\Z'),
                    'Architecture'            => $instanceData->architecture,
                    'Root device type'        => $instanceData->rootDeviceType,
                    'Instance state'          => $instanceData->instanceState->name . " ({$instanceData->instanceState->code})",
                    'Placement'               => isset($instanceData->placement) ? $instanceData->placement->availabilityZone : null,
                    'Tenancy'                 => isset($instanceData->placement) ? $instanceData->placement->tenancy : null,
                    'EBS Optimized'           => $instanceData->ebsOptimized ? "Yes" : "No",
                    'Monitoring (CloudWatch)' => $monitoring,
                    'Security groups'         => implode(', ', $groups)
                );
                if ($instanceData->subnetId) {
                    $retval['VPC ID'] = $instanceData->vpcId;
                    $retval['Subnet ID'] = $instanceData->subnetId;
                    $retval['SourceDesk Check'] = $instanceData->sourceDestCheck;

                    $ni = $instanceData->networkInterfaceSet->get(0);
                    if ($ni)
                        $retval['Network Interface'] = $ni->networkInterfaceId;
                }
                if ($instanceData->reason) {
                    $retval['Reason'] = $instanceData->reason;
                }

                return $retval;
            }
        } catch (Exception $e) {
        }

        return false;
    }

    public function getRoutingTable($type, $aws, $networkInterfaceId = null, $vpcId) {

        //Check for routing table
        $filter = array(array(
            'name'  => RouteTableFilterNameType::vpcId(),
            'value' => $vpcId,
        ), array(
            'name'  => RouteTableFilterNameType::tagKey(),
            'value' => 'scalr-rt-type'
        ), array(
            'name'  => RouteTableFilterNameType::tagValue(),
            'value' => $type
        ));

        $list = $aws->ec2->routeTable->describe(null, $filter);
        if ($list->count() > 0) {
            if ($type == Scalr_Role_Behavior_Router::INTERNET_ACCESS_FULL)
                $routingTable = $list->get(0);
            else {
                /* @var $routingTable \Scalr\Service\Aws\Ec2\DataType\RouteTableData */
                foreach($list as $rTable) {
                    foreach ($rTable->tagSet as $tag) {
                        if ($tag->key == 'scalr-vpc-nid' && $tag->value == $networkInterfaceId) {
                            $routingTable = $rTable;
                            break;
                        }
                    }

                    if ($routingTable)
                        break;
                }
            }
        }

        $tags = array(
            array('key' => "scalr-id", 'value' => SCALR_ID),
            array('key' => "scalr-rt-type", 'value' => $type),
            array('key' => "Name", 'value' => "Scalr System Routing table for {$type} internet access")
        );

        if (!$routingTable) {
            // Create routing table for FULL internet access
            $routingTable = $aws->ec2->routeTable->create($vpcId);
            // Add new route for internet
            if ($type == Scalr_Role_Behavior_Router::INTERNET_ACCESS_FULL) {
                // GET IGW
                $igwList = $aws->ec2->internetGateway->describe(null, array(array(
                    'name'  => InternetGatewayFilterNameType::attachmentVpcId(),
                    'value' => $vpcId,
                )));
                $igw = $igwList->get(0);
                if (!$igw) {
                    $igw = $aws->ec2->internetGateway->create();
                    $aws->ec2->internetGateway->attach($igw->internetGatewayId, $vpcId);

                    try {
                        $igw->createTags(array(
                            array('key' => "scalr-id", 'value' => SCALR_ID),
                            array('key' => "Name", 'value' => 'Scalr System IGW')
                        ));
                    } catch (Exception $e) {}
                }
                $igwId = $igw->internetGatewayId;

                // Add new route for internet
                $aws->ec2->routeTable->createRoute($routingTable->routeTableId, '0.0.0.0/0', $igwId);
            } else {
                //outbound-only
                $aws->ec2->routeTable->createRoute($routingTable->routeTableId, '0.0.0.0/0', null, null,
                    $networkInterfaceId
                );

                $tags[] = array('key' => "scalr-vpc-nid", 'value' => $networkInterfaceId);
            }

            try {
                $routingTable->createTags($tags);
            } catch (Exception $e) {}
        }

        return $routingTable->routeTableId;
    }

    /**
     * {@inheritdoc}
     * @see IPlatformModule::LaunchServer()
     */
    public function LaunchServer(DBServer $DBServer, Scalr_Server_LaunchOptions $launchOptions = null)
    {
        $runInstanceRequest = new RunInstancesRequestData(
            (isset($launchOptions->imageId) ? $launchOptions->imageId : null), 1, 1
        );

        $environment = $DBServer->GetEnvironmentObject();

        $placementData = null;
        $noSecurityGroups = false;

        if (!$launchOptions) {
            $launchOptions = new Scalr_Server_LaunchOptions();
            $DBRole = DBRole::loadById($DBServer->roleId);

            $dbFarmRole = $DBServer->GetFarmRoleObject();

            $runInstanceRequest->setMonitoring(
                $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_ENABLE_CW_MONITORING)
            );

            $launchOptions->imageId = $DBRole->getImageId(
                SERVER_PLATFORMS::EC2,
                $dbFarmRole->CloudLocation
            );

            // Need OS Family to get block device mapping for OEL roles
            $imageInfo = $DBRole->getImageDetails(
                SERVER_PLATFORMS::EC2,
                $dbFarmRole->CloudLocation
            );
            $launchOptions->osFamily = $imageInfo['os_family'];

            $launchOptions->cloudLocation = $dbFarmRole->CloudLocation;

            $akiId = $DBServer->GetProperty(EC2_SERVER_PROPERTIES::AKIID);
            if (!$akiId) {
                $akiId = $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_AKI_ID);
            }

            if ($akiId) {
                $runInstanceRequest->kernelId = $akiId;
            }

            $ariId = $DBServer->GetProperty(EC2_SERVER_PROPERTIES::ARIID);
            if (!$ariId) {
                $ariId = $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_ARI_ID);
            }

            if ($ariId) {
                $runInstanceRequest->ramdiskId = $ariId;
            }

            $i_type = $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_INSTANCE_TYPE);
            if (!$i_type) {
                $DBRole = DBRole::loadById($DBServer->roleId);
                $i_type = $DBRole->getProperty(EC2_SERVER_PROPERTIES::INSTANCE_TYPE);
            }

            $launchOptions->serverType = $i_type;

            if ($dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_EBS_OPTIMIZED) == 1) {
                $runInstanceRequest->ebsOptimized = true;
            } else {
                $runInstanceRequest->ebsOptimized = false;
            }

            foreach ($DBServer->GetCloudUserData() as $k => $v) {
                $u_data .= "{$k}={$v};";
            }

            $runInstanceRequest->userData = base64_encode(trim($u_data, ";"));

            $vpcId = $dbFarmRole->GetFarmObject()->GetSetting(DBFarm::SETTING_EC2_VPC_ID);
            if ($vpcId) {
                if ($DBRole->hasBehavior(ROLE_BEHAVIORS::VPC_ROUTER)) {
                    $networkInterface = new InstanceNetworkInterfaceSetRequestData();
                    $networkInterface->networkInterfaceId = $dbFarmRole->GetSetting(Scalr_Role_Behavior_Router::ROLE_VPC_NID);
                    $networkInterface->deviceIndex = 0;
                    $networkInterface->deleteOnTermination = false;

                    $runInstanceRequest->setNetworkInterface($networkInterface);
                    $noSecurityGroups = true;
                } else {

                    $vpcSubnetId = $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_VPC_SUBNET_ID);
                    $vpcInternetAccess = $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_VPC_INTERNET_ACCESS);
                    if (!$vpcSubnetId) {
                        $aws = $environment->aws($launchOptions->cloudLocation);

                        $subnet = $this->AllocateNewSubnet(
                            $aws->ec2,
                            $vpcId,
                            $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_VPC_AVAIL_ZONE),
                            24
                        );

                        try {
                            $subnet->createTags(array(
                                array('key' => "scalr-id", 'value' => SCALR_ID),
                                array('key' => "scalr-sn-type", 'value' => $vpcInternetAccess),
                                array('key' => "Name", 'value' => 'Scalr System Subnet')
                            ));
                        } catch (Exception $e) {}

                        try {

                            $routeTableId = $dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_VPC_ROUTING_TABLE_ID);

                            Logger::getLogger('VPC')->warn(new FarmLogMessage($DBServer->farmId, "Internet access: {$vpcInternetAccess}"));

                            if (!$routeTableId) {
                                if ($vpcInternetAccess == Scalr_Role_Behavior_Router::INTERNET_ACCESS_OUTBOUND) {
                                    $routerRole = $DBServer->GetFarmObject()->GetFarmRoleByBehavior(ROLE_BEHAVIORS::VPC_ROUTER);
                                    if (!$routerRole) {
                                        if (\Scalr::config('scalr.instances_connection_policy') != 'local')
                                            throw new Exception("Outbound access require VPC router role in farm");
                                    }

                                    $networkInterfaceId = $routerRole->GetSetting(Scalr_Role_Behavior_Router::ROLE_VPC_NID);

                                    Logger::getLogger('EC2')->warn(new FarmLogMessage($DBServer->farmId, "Requesting outbound routing table. NID: {$networkInterfaceId}"));

                                    $routeTableId = $this->getRoutingTable($vpcInternetAccess, $aws, $networkInterfaceId, $vpcId);

                                    Logger::getLogger('EC2')->warn(new FarmLogMessage($DBServer->farmId, "Routing table ID: {$routeTableId}"));

                                } elseif ($vpcInternetAccess == Scalr_Role_Behavior_Router::INTERNET_ACCESS_FULL) {
                                    $routeTableId = $this->getRoutingTable($vpcInternetAccess, $aws, null, $vpcId);
                                }
                            }

                            $aws->ec2->routeTable->associate($routeTableId, $subnet->subnetId);

                        } catch (Exception $e) {

                            Logger::getLogger('EC2')->warn(new FarmLogMessage($DBServer->farmId, "Removing allocated subnet, due to routing table issues"));

                            $aws->ec2->subnet->delete($subnet->subnetId);
                            throw $e;
                        }

                        $vpcSubnetId = $subnet->subnetId;
                        $dbFarmRole->SetSetting(DBFarmRole::SETTING_AWS_VPC_SUBNET_ID, $vpcSubnetId, DBFarmRole::TYPE_LCL);
                    }

                    if ($vpcSubnetId) {
                        $runInstanceRequest->subnetId = $vpcSubnetId;
                    } else
                        throw new Exception("Unable to define subnetId for role in VPC");
                }
            }
        } else {
            $runInstanceRequest->userData = base64_encode(trim($launchOptions->userData));
        }

        $governance = new Scalr_Governance($DBServer->envId);

        $aws = $environment->aws($launchOptions->cloudLocation);

        if (!$vpcId) {
            $vpcId = $environment->getPlatformConfigValue(self::DEFAULT_VPC_ID.".{$launchOptions->cloudLocation}");

            if ($vpcId === null || $vpcId === false) {
                $vpcId = "";
                $list = $aws->ec2->describeAccountAttributes(array('default-vpc'));
                foreach ($list as $item) {
                    if ($item->attributeName == 'default-vpc') {
                        $vpcId = $item->attributeValueSet[0]->attributeValue;
                    }
                }
                if ($vpcId == 'none') {
                    $vpcId = '';
                }
                $environment->setPlatformConfig(array(
                    self::DEFAULT_VPC_ID . ".{$launchOptions->cloudLocation}" => $vpcId
                ));
            }
        }

        // Set AMI, AKI and ARI ids
        $runInstanceRequest->imageId = $launchOptions->imageId;

        $runInstanceRequest->instanceInitiatedShutdownBehavior = 'terminate';

        if (!$noSecurityGroups) {

            foreach ($this->GetServerSecurityGroupsList($DBServer, $aws->ec2, $vpcId, $governance) as $sgroup) {
                $runInstanceRequest->appendSecurityGroupId($sgroup);
            }

            if (!$runInstanceRequest->subnetId) {
                // Set availability zone
                if (!$launchOptions->availZone) {
                    $avail_zone = $this->GetServerAvailZone($DBServer, $aws->ec2, $launchOptions);
                    if ($avail_zone) {
                        $placementData = new PlacementResponseData($avail_zone);
                    }
                } else {
                    $placementData = new PlacementResponseData($launchOptions->availZone);
                }
            }
        }

        $runInstanceRequest->minCount = 1;
        $runInstanceRequest->maxCount = 1;

        // Set instance type
        $runInstanceRequest->instanceType = $launchOptions->serverType;

        if ($launchOptions->serverType == 'hi1.4xlarge' || $launchOptions->serverType == 'cc2.8xlarge' || $launchOptions->osFamily == 'oel') {
            foreach ($this->GetBlockDeviceMapping($launchOptions->serverType) as $bdm) {
                $runInstanceRequest->appendBlockDeviceMapping($bdm);
            }
        }

        if (in_array($runInstanceRequest->instanceType, array('cc1.4xlarge', 'cg1.4xlarge', 'cc2.8xlarge', 'hi1.4xlarge'))) {

            $placementGroup = $DBServer->GetFarmRoleObject()->GetSetting(DBFarmRole::SETTING_AWS_CLUSTER_PG);

            if (!$placementGroup && $runInstanceRequest->instanceType != 'hi1.4xlarge') {
                $placementGroup = "scalr-role-{$DBServer->farmRoleId}";
                try {
                    $aws->ec2->placementGroup->create($placementGroup);
                } catch (Exception $e) {
                    if (!stristr($e->getMessage(), "already exists"))
                        throw new Exception(sprintf(
                            _("Cannot launch new instance. Unable to create placement group: %s"),
                            $result->faultstring
                        ));
                }

                $DBServer->GetFarmRoleObject()->SetSetting(DBFarmRole::SETTING_AWS_CLUSTER_PG, $placementGroup, DBFarmRole::TYPE_LCL);
            }

            if ($placementGroup) {
                if ($placementData === null) {
                    $placementData = new PlacementResponseData(null, $placementGroup);
                } else {
                    $placementData->groupName = $placementGroup;
                }
            }
        }

        if ($placementData !== null) {
            $runInstanceRequest->setPlacement($placementData);
        }

        $sshKey = Scalr_SshKey::init();
        if ($DBServer->status == SERVER_STATUS::TEMPORARY) {
            $keyName = "SCALR-ROLESBUILDER-" . SCALR_ID;
            $farmId = 0;
        } else {
            $keyName = $governance->getValue(Scalr_Governance::AWS_KEYPAIR);
            if ($keyName) {
                $skipKeyValidation = true;
            } else {
                $keyName = "FARM-{$DBServer->farmId}-" . SCALR_ID;
                $farmId = $DBServer->farmId;
                $oldKeyName = "FARM-{$DBServer->farmId}";
                if ($sshKey->loadGlobalByName($oldKeyName, $launchOptions->cloudLocation, $DBServer->envId, SERVER_PLATFORMS::EC2)) {
                    $keyName = $oldKeyName;
                    $skipKeyValidation = true;
                }
            }
        }
        if (!$skipKeyValidation && !$sshKey->loadGlobalByName($keyName, $launchOptions->cloudLocation, $DBServer->envId, SERVER_PLATFORMS::EC2)) {
            $result = $aws->ec2->keyPair->create($keyName);
            if ($result->keyMaterial) {
                $sshKey->farmId = $farmId;
                $sshKey->clientId = $DBServer->clientId;
                $sshKey->envId = $DBServer->envId;
                $sshKey->type = Scalr_SshKey::TYPE_GLOBAL;
                $sshKey->cloudLocation = $launchOptions->cloudLocation;
                $sshKey->cloudKeyName = $keyName;
                $sshKey->platform = SERVER_PLATFORMS::EC2;
                $sshKey->setPrivate($result->keyMaterial);
                $sshKey->setPublic($sshKey->generatePublicKey());
                $sshKey->save();
            }
        }

        $runInstanceRequest->keyName = $keyName;

        // Added by OlegZ 
        // Apply IAM role to launched server
        $iamProfileName = $DBServer->GetFarmRoleObject()->GetSetting(DBFarmRole::SETTING_AWS_IAM_PROFILE_NAME);
        $runInstanceRequest->iamInstanceProfile = array('name' => $iamProfileName);

        try {
            $result = $aws->ec2->instance->run($runInstanceRequest);
        } catch (Exception $e) {
            if (stristr($e->getMessage(), "The key pair") && stristr($e->getMessage(), "does not exist")) {
                $sshKey->delete();
                throw $e;
            }

            if (stristr($e->getMessage(), "The requested Availability Zone is no longer supported") ||
                stristr($e->getMessage(), "is not supported in your requested Availability Zone") ||
                stristr($e->getMessage(), "is currently constrained and we are no longer accepting new customer requests")) {

                $availZone = $runInstanceRequest->getPlacement() ?
                    $runInstanceRequest->getPlacement()->availabilityZone : null;

                if ($availZone) {
                    $DBServer->GetEnvironmentObject()->setPlatformConfig(
                        array("aws.{$launchOptions->cloudLocation}.{$availZone}.unavailable" => time())
                    );
                }

                throw $e;

            } else {
                throw $e;
            }
        }

        if ($result->instancesSet->get(0)->instanceId) {
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::AVAIL_ZONE, $result->instancesSet->get(0)->placement->availabilityZone);
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::INSTANCE_ID, $result->instancesSet->get(0)->instanceId);
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::INSTANCE_TYPE, $runInstanceRequest->instanceType);
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::AMIID, $runInstanceRequest->imageId);
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::REGION, $launchOptions->cloudLocation);
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::VPC_ID, $result->instancesSet->get(0)->vpcId);
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::SUBNET_ID, $result->instancesSet->get(0)->subnetId);
            $DBServer->SetProperty(EC2_SERVER_PROPERTIES::ARCHITECTURE, $result->instancesSet->get(0)->architecture);

            $DBServer->osType = $result->instancesSet->get(0)->platform ? $result->instancesSet->get(0)->platform : 'linux';

            return $DBServer;
        } else {
            throw new Exception(sprintf(_("Cannot launch new instance. %s"), serialize($result)));
        }
    }

    public function AllocateNewSubnet(\Scalr\Service\Aws\Ec2 $ec2, $vpcId, $availZone, $subnetLength = 24)
    {
        // HARDCODE THIS
        $subnetLength = 24;

        $subnetsList = $ec2->subnet->describe(null, array(array(
            'name'  => SubnetFilterNameType::vpcId(),
            'value' => $vpcId,
        )));
        $subnets = array();
        foreach ($subnetsList as $subnet) {
            @list($ip, $len) = explode('/', $subnet->cidrBlock);
            $subnets[] = array('min' => ip2long($ip), 'max' => (ip2long($ip) | (1<<(32-$len))-1));
        }

        $vpcInfo = $ec2->vpc->describe($vpcId);
        /* @var $vpc \Scalr\Service\Aws\Ec2\DataType\VpcData */
        $vpc = $vpcInfo->get(0);

        $info = explode("/", $vpc->cidrBlock);
        $startIp = ip2long($info[0]);
        $maxIp = ($startIp | (1<<(32-$info[1]))-1);
        while ($startIp < $maxIp) {
            $sIp = $startIp;
            $eIp = ($sIp | (1<<(32-$subnetLength))-1);
            foreach ($subnets as $subnet) {
                $checkRange = ($subnet['min'] <= $sIp) && ($sIp <= $subnet['max']) && ($subnet['min'] <= $eIp) && ($eIp <= $subnet['max']);
                if ($checkRange)
                    break;
            }
            if ($checkRange) {
                $startIp = $eIp+1;
            } else {
                $subnetIp = long2ip($startIp);
                break;
            }
        }

        return $ec2->subnet->create($vpcId, "{$subnetIp}/{$subnetLength}", $availZone);
    }

    /**
     * Gets block device mapping
     *
     * @param   string     $instanceType The type of the instance
     * @param   string     $prefix       The prefix
     * @return  array      Returns array of the BlockDeviceMappingData
     */
    private function GetBlockDeviceMapping($instanceType, $prefix = '/dev/sd')
    {
        $retval = array();

        //b
        if (in_array($instanceType, array(
                'm1.small', 'c1.medium', 'm1.medium', 'm1.large', 'm1.xlarge',
                'c1.xlarge', 'cc1.4xlarge', 'cc2.8xlarge', 'cr1.8xlarge',
                'm2.xlarge', 'm2.2xlarge', 'm2.4xlarge', 'hi1.4xlarge', 'cr1.8xlarge'))) {
            $retval[] = new BlockDeviceMappingData("{$prefix}b", 'ephemeral0');
        }

        //c
        if (in_array($instanceType, array(
                'm1.large', 'm1.xlarge', 'cc2.8xlarge', 'cc1.4xlarge',
                'c1.xlarge', 'cr1.8xlarge', 'hi1.4xlarge', 'm2.2xlarge', 'cr1.8xlarge'))) {
            $retval[] = new BlockDeviceMappingData("{$prefix}c", 'ephemeral1');
        }

        //e
        if (in_array($instanceType, array('m1.xlarge', 'c1.xlarge', 'cc2.8xlarge'))) {
             $retval[] = new BlockDeviceMappingData("{$prefix}e", 'ephemeral2');
        }

        //f
        if (in_array($instanceType, array('m1.xlarge', 'c1.xlarge', 'cc2.8xlarge'))) {
            $retval[] = new BlockDeviceMappingData("{$prefix}f", 'ephemeral3');
        }

        /*
        //g
        if (in_array($instanceType, array())) {
            $retval[] = new BlockDeviceMappingData("{$prefix}g", 'ephemeral4');
        }

        //h
        if (in_array($instanceType, array())) {
            $retval[] = new BlockDeviceMappingData("{$prefix}h", 'ephemeral5');
        }

        //i
        if (in_array($instanceType, array())) {
            $retval[] = new BlockDeviceMappingData("{$prefix}i", 'ephemeral6');
        }
        */

        return $retval;
    }

    /**
     * Gets the list of the security groups for the specified db server.
     *
     * If server does not have required security groups this method will create them.
     *
     * @param   DBServer               $DBServer The DB Server instance
     * @param   \Scalr\Service\Aws\Ec2 $ec2      Ec2 Client instance
     * @param   string                 $vpcId    optional The ID of VPC
     * @return  array  Returns array looks like array(groupid-1, groupid-2, ..., groupid-N)
     */
    private function GetServerSecurityGroupsList(DBServer $DBServer, \Scalr\Service\Aws\Ec2 $ec2, $vpcId = "", Scalr_Governance $governance = null)
    {
        $retval = array();
        $checkGroups = array();
        $sgGovernance = true;
        $allowAdditionalSgs = true;

        if ($governance) {
            $sgs = $governance->getValue(Scalr_Governance::AWS_SECURITY_GROUPS);
            if ($sgs !== null) {
                $governanceSecurityGroups = @explode(",", $sgs);
                if (!empty($governanceSecurityGroups)) {
                    foreach ($governanceSecurityGroups as $sg) {
                        if ($sg != '')
                            array_push($checkGroups, trim($sg));
                    }
                }

                $sgGovernance = false;
                $allowAdditionalSgs = $governance->getValue(Scalr_Governance::AWS_SECURITY_GROUPS, 'allow_additional_sec_groups');
            }
        }

        if (!$sgGovernance || $allowAdditionalSgs) {
            if ($DBServer->farmRoleId != 0) {
                $dbFarmRole = $DBServer->GetFarmRoleObject();
                if ($dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_SECURITY_GROUPS_LIST) !== null) {
                    // New SG management
                    $sgs = @json_decode($dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_SECURITY_GROUPS_LIST));
                    if (!empty($sgs)) {
                        foreach ($sgs as $sg) {
                            if (stripos($sg, 'sg-') === 0)
                                array_push($retval, $sg);
                            else
                                array_push($checkGroups, $sg);
                        }
                    }
                } else {
                    // Old SG management
                    array_push($checkGroups, 'default');
                    array_push($checkGroups, \Scalr::config('scalr.aws.security_group_name'));
                    if (!$vpcId) {
                        array_push($checkGroups, "scalr-farm.{$DBServer->farmId}");
                        array_push($checkGroups, "scalr-role.{$DBServer->farmRoleId}");
                    }

                    $additionalSgs = trim($dbFarmRole->GetSetting(DBFarmRole::SETTING_AWS_SG_LIST));
                    if ($additionalSgs) {
                        $sgs = explode(",", $additionalSgs);
                        if (!empty($sgs)) {
                            foreach ($sgs as $sg) {
                                $sg = trim($sg);
                                if (stripos($sg, 'sg-') === 0)
                                    array_push($retval, $sg);
                                else
                                    array_push($checkGroups, $sg);
                            }
                        }
                    }
                }
            } else
                array_push($checkGroups, 'scalr-rb-system');
        }

        // No name based security groups, return only SG ids.
        if (empty($checkGroups))
            return $retval;

        // Filter groups
        $filter = array(
            array(
                'name' => SecurityGroupFilterNameType::groupName(),
                'value' => $checkGroups,
            )
        );

        // If instance run in VPC, add VPC filter
        if ($vpcId != '') {
            $filter[] = array(
                'name'  => SecurityGroupFilterNameType::vpcId(),
                'value' => $vpcId
            );
        }

        // Get filtered list of SG required by scalr;
        try {
            $list = $ec2->securityGroup->describe(null, null, $filter);
            $sgList = array();
            foreach ($list as $sg) {
                /* @var $sg \Scalr\Service\Aws\Ec2\DataType\SecurityGroupData */
                if (($vpcId == '' && !$sg->vpcId) || ($vpcId && $sg->vpcId == $vpcId)) {
                    $sgList[$sg->groupName] = $sg->groupId;
                }
            }
            unset($list);
        } catch (Exception $e) {
            throw new Exception("Cannot get list of security groups (1): {$e->getMessage()}");
        }

        foreach ($checkGroups as $groupName) {
            // Check default SG
            if ($groupName == 'default') {
                array_push($retval, $sgList[$groupName]);

            // Check Roles builder SG
            } elseif ($groupName == 'scalr-rb-system') {
                if (!isset($sgList[$groupName])) {
                    try {
                        $securityGroupId = $ec2->securityGroup->create(
                            'scalr-rb-system', "Security group for Roles Builder", $vpcId
                        );
                        $ipRangeList = new IpRangeList();
                        foreach (\Scalr::config('scalr.aws.ip_pool') as $ip) {
                            $ipRangeList->append(new IpRangeData($ip));
                        }

                        sleep(2);

                        $ec2->securityGroup->authorizeIngress(array(
                            new IpPermissionData('tcp', 22, 22, $ipRangeList),
                            new IpPermissionData('tcp', 8008, 8013, $ipRangeList)
                        ), $securityGroupId);

                        $sgList['scalr-rb-system'] = $securityGroupId;
                    } catch (Exception $e) {
                        throw new Exception(sprintf(_("Cannot create security group '%s': %s"), 'scalr-rb-system', $e->getMessage()));
                    }
                }
                array_push($retval, $sgList[$groupName]);

            //Check scalr-farm.* security group
            } elseif (stripos($groupName, 'scalr-farm.') === 0) {
                if (!isset($sgList[$groupName])) {
                    try {
                        $securityGroupId = $ec2->securityGroup->create(
                            $groupName, sprintf("Security group for FarmID N%s", $DBServer->farmId), $vpcId
                        );

                        sleep(2);

                        $userIdGroupPairList = new UserIdGroupPairList(new UserIdGroupPairData(
                            $DBServer->GetEnvironmentObject()->getPlatformConfigValue(self::ACCOUNT_ID),
                            null,
                            $groupName
                        ));

                        $ec2->securityGroup->authorizeIngress(array(
                            new IpPermissionData('tcp', 0, 65535, null, $userIdGroupPairList),
                            new IpPermissionData('udp', 0, 65535, null, $userIdGroupPairList)
                        ), $securityGroupId);

                        $sgList[$groupName] = $securityGroupId;

                    } catch (Exception $e) {
                        throw new Exception(sprintf(
                            _("Cannot create security group '%s': %s"), $groupName, $e->getMessage()
                        ));
                    }
                }
                array_push($retval, $sgList[$groupName]);

            //Check scalr-role.* security group
            } elseif (stripos($groupName, 'scalr-role.') === 0) {
                if (!isset($sgList[$groupName])) {
                    try {
                        $securityGroupId = $ec2->securityGroup->create(
                            $groupName,
                            sprintf("Security group for FarmRoleID N%s on FarmID N%s", $DBServer->GetFarmRoleObject()->ID, $DBServer->farmId),
                            $vpcId
                        );

                        sleep(2);

                        // DB rules
                        $dbRules = $DBServer->GetFarmRoleObject()->GetRoleObject()->getSecurityRules();
                        $groupRules = array();
                        foreach ($dbRules as $rule) {
                            $groupRules[Scalr_Util_CryptoTool::hash($rule['rule'])] = $rule;
                        }

                        // Behavior rules
                        foreach (Scalr_Role_Behavior::getListForFarmRole($DBServer->GetFarmRoleObject()) as $bObj) {
                            $bRules = $bObj->getSecurityRules();
                            foreach ($bRules as $r) {
                                if ($r) {
                                    $groupRules[Scalr_Util_CryptoTool::hash($r)] = array('rule' => $r);
                                }
                            }
                        }

                        // Default rules
                        $userIdGroupPairList = new UserIdGroupPairList(new UserIdGroupPairData(
                            $DBServer->GetEnvironmentObject()->getPlatformConfigValue(self::ACCOUNT_ID),
                            null,
                            $groupName
                        ));
                        $rules = array(
                            new IpPermissionData('tcp', 0, 65535, null, $userIdGroupPairList),
                            new IpPermissionData('udp', 0, 65535, null, $userIdGroupPairList)
                        );

                        foreach ($groupRules as $rule) {
                            $group_rule = explode(":", $rule["rule"]);
                            $rules[] = new IpPermissionData(
                                $group_rule[0], $group_rule[1], $group_rule[2],
                                new IpRangeData($group_rule[3])
                            );
                        }

                        $ec2->securityGroup->authorizeIngress($rules, $securityGroupId);

                        $sgList[$groupName] = $securityGroupId;

                    } catch (Exception $e) {
                        throw new Exception(sprintf(_("Cannot create security group '%s': %s"), $groupName, $e->getMessage()));
                    }
                }
                array_push($retval, $sgList[$groupName]);
            } elseif ($groupName == \Scalr::config('scalr.aws.security_group_name')) {
                if (!isset($sgList[$groupName])) {
                    try {
                        $securityGroupId = $ec2->securityGroup->create(
                            $groupName, "Security rules needed by Scalr", $vpcId
                        );

                        $ipRangeList = new IpRangeList();
                        foreach (\Scalr::config('scalr.aws.ip_pool') as $ip) {
                            $ipRangeList->append(new IpRangeData($ip));
                        }
                        // TODO: Open only FOR VPC ranges
                        $ipRangeList->append(new IpRangeData('10.0.0.0/8'));

                        sleep(2);

                        $ec2->securityGroup->authorizeIngress(array(
                            new IpPermissionData('tcp', 3306, 3306, $ipRangeList),
                            new IpPermissionData('tcp', 8008, 8013, $ipRangeList),
                            new IpPermissionData('udp', 8014, 8014, $ipRangeList),
                        ), $securityGroupId);

                        $sgList[$groupName] = $securityGroupId;

                    } catch (Exception $e) {
                        throw new Exception(sprintf(_("Cannot create security group '%s': %s"), $groupName, $e->getMessage()));
                    }
                }
                array_push($retval, $sgList[$groupName]);
            } else {
                if (!isset($sgList[$groupName])) {
                    throw new Exception(sprintf(_("Security group '%s' is not found"), $groupName));
                } else
                    array_push($retval, $sgList[$groupName]);
            }
        }

        return $retval;
    }


    /**
     * Gets Avail zone for the specified DB server
     *
     * @param   DBServer                   $DBServer
     * @param   \Scalr\Service\Aws\Ec2     $ec2
     * @param   Scalr_Server_LaunchOptions $launchOptions
     */
    private function GetServerAvailZone(DBServer $DBServer, \Scalr\Service\Aws\Ec2 $ec2,
                                        Scalr_Server_LaunchOptions $launchOptions)
    {
        if ($DBServer->status == SERVER_STATUS::TEMPORARY)
            return false;

        $aws = $DBServer->GetEnvironmentObject()->aws($DBServer);

        $server_avail_zone = $DBServer->GetProperty(EC2_SERVER_PROPERTIES::AVAIL_ZONE);

        if ($DBServer->replaceServerID && !$server_avail_zone) {
            try {
                $rDbServer = DBServer::LoadByID($DBServer->replaceServerID);
                $server_avail_zone = $rDbServer->GetProperty(EC2_SERVER_PROPERTIES::AVAIL_ZONE);
            } catch (Exception $e) {
            }
        }

        $role_avail_zone = $this->db->GetOne("
            SELECT ec2_avail_zone FROM ec2_ebs
            WHERE server_index=? AND farm_roleid=?
            LIMIT 1
        ",
            array($DBServer->index, $DBServer->farmRoleId)
        );

        if (!$role_avail_zone) {
            $DBServer->SetProperty("tmp.ec2.avail_zone.algo1", "[S={$server_avail_zone}][R1:{$role_avail_zone}]");

            if ($server_avail_zone &&
                $server_avail_zone != 'x-scalr-diff' &&
                !stristr($server_avail_zone, "x-scalr-custom")) {
                return $server_avail_zone;
            }

            $role_avail_zone = $DBServer->GetFarmRoleObject()->GetSetting(DBFarmRole::SETTING_AWS_AVAIL_ZONE);
        }

        $DBServer->SetProperty("tmp.ec2.avail_zone.algo2", "[S={$server_avail_zone}][R2:{$role_avail_zone}]");

        if (!$role_avail_zone) {
            return false;
        }

        if ($role_avail_zone == "x-scalr-diff" || stristr($role_avail_zone, "x-scalr-custom")) {
            //TODO: Elastic Load Balancer
            $avail_zones = array();
            if (stristr($role_avail_zone, "x-scalr-custom")) {
                $zones = explode("=", $role_avail_zone);
                foreach (explode(":", $zones[1]) as $zone) {
                    if ($zone != "") {
                        array_push($avail_zones, $zone);
                    }
                }

            } else {
                // Get list of all available zones
                $avail_zones_resp = $ec2->availabilityZone->describe();
                foreach ($avail_zones_resp as $zone) {
                    /* @var $zone \Scalr\Service\Aws\Ec2\DataType\AvailabilityZoneData */
                    $zoneName = $zone->zoneName;

                    if (strstr($zone->zoneState, 'available')) {
                        $isUnavailable = $DBServer->GetEnvironmentObject()->getPlatformConfigValue(
                            "aws.{$launchOptions->cloudLocation}.{$zoneName}.unavailable",
                            false
                        );
                        if ($isUnavailable && $isUnavailable + 3600 < time()) {
                            $DBServer->GetEnvironmentObject()->setPlatformConfig(
                                array(
                                    "aws.{$launchOptions->cloudLocation}.{$zoneName}.unavailable" => false
                                ),
                                false
                            );
                            $isUnavailable = false;
                        }

                        if (!$isUnavailable) {
                            array_push($avail_zones, $zoneName);
                        }
                    }
                }
            }

            sort($avail_zones);
            $avail_zones = array_reverse($avail_zones);

            $servers = $DBServer->GetFarmRoleObject()->GetServersByFilter(array("status" => array(
                SERVER_STATUS::RUNNING,
                SERVER_STATUS::INIT,
                SERVER_STATUS::PENDING
            )));
            $availZoneDistribution = array();
            foreach ($servers as $cDbServer) {
                if ($cDbServer->serverId != $DBServer->serverId) {
                    $availZoneDistribution[$cDbServer->GetProperty(EC2_SERVER_PROPERTIES::AVAIL_ZONE)]++;
                }
            }

            $sCount = 1000000;
            foreach ($avail_zones as $zone) {
                if ((int)$availZoneDistribution[$zone] <= $sCount) {
                    $sCount = (int)$availZoneDistribution[$zone];
                    $availZone = $zone;
                }
            }

            $aZones = implode(",", $avail_zones);
            $dZones = "";
            foreach ($availZoneDistribution as $zone => $num) {
                $dZones .= "({$zone}:{$num})";
            }

            $DBServer->SetProperty("tmp.ec2.avail_zone.algo2", "[A:{$aZones}][D:{$dZones}][S:{$availZone}]");

            return $availZone;
        } else {
            return $role_avail_zone;
        }
    }

    public function GetPlatformAccessData($environment, DBServer $DBServer)
    {
        $accessData = new stdClass();
        $accessData->accountId = $environment->getPlatformConfigValue(self::ACCOUNT_ID);
        $accessData->keyId = $environment->getPlatformConfigValue(self::ACCESS_KEY);
        $accessData->key = $environment->getPlatformConfigValue(self::SECRET_KEY);
        $accessData->cert = $environment->getPlatformConfigValue(self::CERTIFICATE);
        $accessData->pk = $environment->getPlatformConfigValue(self::PRIVATE_KEY);

        return $accessData;
    }

    public function PutAccessData(DBServer $DBServer, Scalr_Messaging_Msg $message)
    {
        $put = false;
        $put |= $message instanceof Scalr_Messaging_Msg_Rebundle;
        $put |= $message instanceof Scalr_Messaging_Msg_BeforeHostUp;
        $put |= $message instanceof Scalr_Messaging_Msg_HostInitResponse;
        $put |= $message instanceof Scalr_Messaging_Msg_Mysql_PromoteToMaster;
        $put |= $message instanceof Scalr_Messaging_Msg_Mysql_CreateDataBundle;
        $put |= $message instanceof Scalr_Messaging_Msg_Mysql_CreateBackup;
        $put |= $message instanceof Scalr_Messaging_Msg_BeforeHostTerminate;
        $put |= $message instanceof Scalr_Messaging_Msg_MountPointsReconfigure;

        $put |= $message instanceof Scalr_Messaging_Msg_DbMsr_PromoteToMaster;
        $put |= $message instanceof Scalr_Messaging_Msg_DbMsr_CreateDataBundle;
        $put |= $message instanceof Scalr_Messaging_Msg_DbMsr_CreateBackup;
        $put |= $message instanceof Scalr_Messaging_Msg_DbMsr_NewMasterUp;


        if ($put) {
            $environment = $DBServer->GetEnvironmentObject();
            $message->platformAccessData = $this->GetPlatformAccessData($environment, $DBServer);
        }
    }

    public function ClearCache ()
    {
        $this->instancesListCache = array();
    }
}
