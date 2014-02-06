Scalr.regPage('Scalr.ui.farms.builder.tabs.elb', function (moduleParams) {
	return Ext.create('Scalr.ui.FarmsBuilderTab', {
		tabTitle: 'ELB',
        itemId: 'elb',
		layout: 'anchor',
		tabData: null,
        originalElbIds: {},
        
        /*settings: {
            'aws.elb.enabled': undefined,
            'aws.elb.id': undefined,
            'aws.elb.remove': undefined
        },*/

		isEnabled: function (record) {
            return record.get('platform') == 'ec2' && !record.get('behaviors').match("cf_");
		},

		beforeShowTab: function (record, handler) {
            Scalr.CachedRequestManager.get('farmbuilder').load(
                {
                    url: '/platforms/ec2/xListElb',
                    params: {
                        cloudLocation: record.get('cloud_location')
                    }
                },
                function(data, status) {
                    this.tabData = data;
                    status ? handler() : this.deactivateTab();
                },
                this,
                0
            );
		},

		showTab: function (record) {
			var settings = record.get('settings'), 
                elbIdField = this.down('[name="aws.elb.id"]'),
                farmRoleId = record.get('farm_role_id');
			
            this.vpc = this.up('#fbcard').down('#farm').getVpcSettings();
            
            // Oleg Z chnage
            // Show ELB configuration regardless of deployment options
            if (true || this.vpc === false) {
                this.isLoading = true;
                
                if (settings['lb.use_elb'] == 1) {//load settings from old balancing tab
                    settings['aws.elb.id'] = settings['lb.name'];
                    settings['aws.elb.enabled'] = settings['lb.use_elb'];
                    settings['lb.use_elb'] = 0;
                    record.set('settings', settings);
                }
                //store original elb id
                if (farmRoleId && this.originalElbIds[farmRoleId] === undefined) {
                    this.originalElbIds[farmRoleId] = settings['aws.elb.id'] || null;
                }

                this.down('[name="aws.elb"]')[settings['aws.elb.enabled'] == 1 ? 'expand' : 'collapse']();

                elbIdField.store.loadData(this.tabData || []);
                elbIdField.setValue(settings['aws.elb.id'] || '');
                elbIdField.scalrParams = {
                    farmId: moduleParams['farmId'],
                    roleId: record.get('role_id')
                };
                elbIdField.getPlugin('comboaddnew').postUrl = '?cloudLocation=' + record.get('cloud_location');
                this.down('#warningUsed').hide();
                this.isLoading = false;

                this.toggleElbRemoveWarning(settings['aws.elb.remove']);
            } else {
                this.down('#vpc_warning').show();
                this.down('[name="aws.elb"]').disable();
            }
		},

		hideTab: function (record) {
            if (true || this.vpc === false) {
                var settings = record.get('settings');

                settings['aws.elb.id'] = '';
                settings['aws.elb.enabled'] = 0;
                settings['aws.elb.remove'] = 0;

                if (! this.down('[name="aws.elb"]').collapsed) {
                    var value = this.down('[name="aws.elb.id"]').getValue();
                    settings['aws.elb.enabled'] = 1;
                    settings['aws.elb.id'] = value;
                }
                if (this.down('#removeElb').isVisible()) {
                    settings['aws.elb.remove'] = this.down('[name="aws.elb.remove"]').getValue() ? 1 : 0;
                }

                this.down('[name="aws.elb"]').collapse();
                this.down('[name="aws.elb.remove"]').setValue(false);
                this.down('#removeElb').hide();

                record.set('settings', settings);
            } else {
                this.down('#vpc_warning').hide();
                this.down('[name="aws.elb"]').enable();
            }
		},

        toggleElbRemoveWarning: function(remove) {
            if (this.isLoading) return;
            var farmRoleId = this.currentRole.get('farm_role_id'), 
                elbId = farmRoleId ? this.originalElbIds[farmRoleId] : null,
                elbIdField = this.down('[name="aws.elb.id"]'),
                elbRemoveField = this.down('[name="aws.elb.remove"]'),
                rec;
            
            if (!Ext.isEmpty(elbId) && (elbIdField.getValue() != elbId || this.down('[name="aws.elb"]').collapsed)) {
                rec = elbIdField.findRecordByValue(elbId);
                this.down('#removeElb').show();
                if (remove !== undefined) {
                    elbRemoveField.setValue(remove);
                }
                elbRemoveField.setElbHostname(rec ? rec.get('hostname') : elbId);
            } else {
                this.down('#removeElb').hide();
                this.down('[name="aws.elb.remove"]').setValue(0);
            }
        },

		items: [{
			xtype: 'displayfield',
            itemId: 'vpc_warning',
            hidden: true,
            anchor: '100%',
			cls: 'x-form-field-warning x-form-field-warning-fit',
			value: 'Scalr doesn\'t support ELB in VPC farms yet.'
        },{
			xtype: 'fieldset',
			title: 'Use <a target="_blank" href="http://aws.amazon.com/elasticloadbalancing/">Amazon Elastic Load Balancer</a> to balance load between instances of this role',
			name: 'aws.elb',
			checkboxToggle: true,
			collapsed: true,
			items: [{
				xtype: 'container',
				layout: 'hbox',
				items: [{
					xtype: 'combo',
					store: {
						fields: [ 'name', 'hostname', 'used', 'farmId', 'farmName', 'roleId', 'roleName' ],
						proxy: 'object'
					},
					flex: 1,
					maxWidth: 500,
					valueField: 'name',
					displayField: 'hostname',
					forceSelection: true,
					name: 'aws.elb.id',
					allowBlank: false,
					emptyText: 'Please select ELB',
					queryMode: 'local',
					listConfig: {
						cls: 'x-boundlist-alt',
						tpl:
							'<tpl for="."><div class="x-boundlist-item" style="height: auto; width: auto">{hostname} ('+
                            '<tpl if="!used">'+
                                '<span style="color: #138913">Not used in Scalr</span>'+
                            '<tpl else>'+
                                '<span style="color:orange">Used by {farmName} -> {roleName}</span>' +
                            '</tpl>' +
							')</div></tpl>'
					},
					plugins: [{
						ptype: 'comboaddnew',
						pluginId: 'comboaddnew',
						url: '/tools/aws/ec2/elb/create'
					}],
					listeners: {
                        addnew: function(item) {
                            Scalr.CachedRequestManager.get('farmbuilder').setExpired({
                                url: '/platforms/ec2/xListElb',
                                params: {
                                    cloudLocation: this.up('#elb').currentRole.get('cloud_location')
                                }
                            });
                        },
						change: function() {
							var r = this.findRecord(this.valueField, this.getValue());
							if (r && r.get('farmId') && r.get('roleId') && r.get('farmId') == this.scalrParams.farmId && r.get('roleId') == this.scalrParams.roleId) {
								this.up().next().show();
							} else {
								this.up().next().hide();
							}
                            this.up('#elb').toggleElbRemoveWarning();
						}
					}
				}, {
					xtype: 'button',
					margin: '0 0 0 12',
					hidden: true,
					text: 'Manage'
				}]
			}, {
				xtype: 'displayfield',
				cls: 'x-form-field-info',
				itemId: 'warningUsed',
				//anchor: '100%',
				value: 'Warning'
			}],
			listeners: {
				collapse: function() {
                    this.up('#elb').toggleElbRemoveWarning();
				},

				expand: function() {
					this.up('#elb').toggleElbRemoveWarning();
				}
			}
		}, {
			xtype: 'container',
			itemId: 'removeElb',
            cls: 'x-container-fieldset',
			hidden: true,
			items: [{
				xtype: 'checkbox',
				name: 'aws.elb.remove',
                setElbHostname: function(hostname) {
                    this.boxLabelEl.setHTML('Check to remove <b>'+hostname+'</b> ELB from cloud after saving farm');
                    this.updateLayout();
                },
				boxLabel: 'remove'
			}]
        }]
	});
});
