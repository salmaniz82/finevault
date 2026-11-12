"""Run with Blender --background --factory-startup --python build-vault.py."""
import bpy, math
from pathlib import Path
from mathutils import Vector
root = Path(__file__).resolve().parents[2]
out = root / 'output/vault-preview'
out.mkdir(exist_ok=True, parents=True)
bpy.ops.wm.read_factory_settings(use_empty=True)
s = bpy.context.scene
s.render.engine = 'CYCLES'
s.cycles.samples = 8
s.cycles.use_denoising = True
s.render.resolution_x, s.render.resolution_y = 960, 540
s.render.resolution_percentage = 100
s.render.fps = 24
s.frame_end = 60
s.view_settings.view_transform = 'Standard'
s.world = bpy.data.worlds.new('Soft ambient')
s.world.color = (.18,.16,.13)
img = bpy.data.images.load(str(root/'application/public/images/login-background.webp'))
img.pack()
photo = bpy.data.materials.new('Fineworth photographic finish')
photo.use_nodes = True
n = photo.node_tree.nodes
tex = n.new('ShaderNodeTexImage')
tex.image = img
em = n.new('ShaderNodeEmission')
photo.node_tree.links.new(tex.outputs['Color'],em.inputs[0])
photo.node_tree.links.new(em.outputs[0],n.get('Material Output').inputs[0])
def metal(name, color, metallic=.7):
    m=bpy.data.materials.new(name)
    m.diffuse_color=(*color,1)
    m.use_nodes=True
    p=m.node_tree.nodes.get('Principled BSDF')
    p.inputs['Base Color'].default_value=(*color,1)
    p.inputs['Metallic'].default_value=metallic
    p.inputs['Roughness'].default_value=.34
    return m
brass=metal('Brushed bronze',(.37,.19,.057))
dark=metal('Recessed steel',(.027,.031,.027))
gold=metal('Polished brass',(.72,.40,.12))
def surface(name, coords, z):
    mesh=bpy.data.meshes.new(name)
    mesh.from_pydata([(x,y,z) for x,y in coords],[],[list(range(len(coords)))])
    obj=bpy.data.objects.new(name,mesh)
    s.collection.objects.link(obj)
    mesh.materials.append(photo)
    uv=mesh.uv_layers.new()
    for loop in mesh.loops:
        x,y=coords[loop.vertex_index]
        uv.data[loop.index].uv=(x/16.72+.5,y/9.41+.5)
    return obj
surface('Marble room and fixed vault surround',[(-8.36,-4.705),(8.36,-4.705),(8.36,4.705),(-8.36,4.705)],0)
cx,cy=3.40,.65
rx,ry=3.52,3.65
def ellipse(x,y,a,b):
    return [(x+a*math.cos(i*math.tau/128),y+b*math.sin(i*math.tau/128)) for i in range(128)]
def disk(name,x,y,z,a,b,depth,mat):
    bpy.ops.mesh.primitive_cylinder_add(vertices=128,radius=1,depth=depth,location=(x,y,z))
    obj=bpy.context.object
    obj.name=name
    obj.scale=(a,b,1)
    obj.data.materials.append(mat)
    return obj
def box(name,loc,size,mat):
    bpy.ops.mesh.primitive_cube_add(size=1,location=loc)
    o=bpy.context.object
    o.name=name
    o.dimensions=size
    o.data.materials.append(mat)
    return o
def pivot(name,loc):
    o=bpy.data.objects.new(name,None)
    s.collection.objects.link(o)
    o.location=loc
    bpy.context.view_layer.update()
    return o
def attach(o,parent):
    o.parent=parent
    o.matrix_parent_inverse=parent.matrix_world.inverted()
def keys(o,prop,values):
    for frame,value in values:
        setattr(o,prop,value)
        o.keyframe_insert(data_path=prop,frame=frame)
# A closed solid leaf fits a stationary circular aperture. Deposit drawers sit behind it.
disk('Aperture liner',cx,cy,.024,rx,ry,.04,brass)
disk('Interior shadow',cx,cy,.055,rx*.965,ry*.965,.025,dark)
for row in range(-3,4):
    for col in range(-3,4):
        x,y=col*.79,row*.62
        if ((abs(x)+.37)/(rx*.91))**2+((abs(y)+.29)/(ry*.91))**2>1: continue
        box('Deposit drawer',(cx+x,cy+y,.094),(.75,.58,.052),brass)
        box('Recessed face',(cx+x,cy+y,.128),(.70,.53,.02),dark)
        box('Brass pull',(cx+x,cy+y,.15),(.20,.033,.03),gold)
hinge=pivot('Door hinge',(cx+rx,cy,.38))
leaf=disk('Solid steel door',cx,cy,.38,rx,ry,.39,brass)
attach(leaf,hinge)
face=surface('Door front',ellipse(cx,cy,rx,ry),.58)
attach(face,hinge)
wheel=pivot('Handle spindle',(cx,cy,.60))
attach(wheel,hinge)
handle=surface('Rotating mechanism',ellipse(cx,cy,2.23,2.36),.607)
attach(handle,wheel)
# Project the original door finish across the entire sealed leaf so the
# source photograph's narrow opening does not appear in the closed pose.
for piece in (face, handle):
    for uv in piece.data.uv_layers.active.data:
        x, y = (uv.uv.x-.5)*16.72, (uv.uv.y-.5)*9.41
        uv.uv = (.5+(3.60+(x-cx)*.81)/16.72, .5+(.67+(y-cy)*.94)/9.41)
keys(wheel,'rotation_euler',[(1,(0,0,0)),(17,(0,0,0)),(34,(0,0,-.68))])
for i in range(8):
    a=i*math.tau/8
    x,y=math.cos(a),math.sin(a)
    bolt=box('Radial locking bolt',(cx+rx*.93*x,cy+ry*.93*y,.62),(.36,.125,.09),brass)
    bolt.rotation_euler.z=a
    attach(bolt,hinge)
    start=bolt.location.copy()
    keys(bolt,'location',[(1,start),(25,start),(36,start-Vector((x*.36,y*.36,0)))])
keys(hinge,'rotation_euler',[(1,(0,0,0)),(35,(0,0,0)),(60,(0,math.radians(84),0))])
bpy.ops.object.light_add(type='AREA',location=(0,5,8))
light=bpy.context.object
light.rotation_euler=(Vector((cx,cy,0))-light.location).to_track_quat('-Z','Y').to_euler()
light.data.energy=1600
light.data.shape='DISK'
light.data.size=5
bpy.ops.object.camera_add(location=(0,0,18))
cam=bpy.context.object
s.camera=cam
cam.data.type='ORTHO'
keys(cam,'location',[(1,(0,0,18)),(7,(0,0,18)),(34,(cx,cy,18)),(60,(cx,cy,18))])
keys(cam.data,'ortho_scale',[(1,16.72),(7,16.72),(34,9.5),(60,8.75)])
for frame,name in [(1,'Closed poster'),(7,'Center camera'),(17,'Turn handle'),(25,'Retract bolts'),(35,'Swing door'),(60,'Enter portal')]:
    s.timeline_markers.new(name,frame=frame)
s.frame_set(1)
for area in bpy.context.screen.areas:
    if area.type=='VIEW_3D':
        area.spaces.active.region_3d.view_perspective='CAMERA'
        area.spaces.active.shading.color_type='TEXTURE'
        area.spaces.active.overlay.show_overlays=False
bpy.context.preferences.filepaths.save_version = 0
s.render.image_settings.file_format='WEBP'
s.render.image_settings.quality=100
s.render.filepath=str(out/'vault-closed.webp')
bpy.ops.render.render(write_still=True)
s.render.image_settings.media_type='VIDEO'
s.render.image_settings.file_format='FFMPEG'
s.render.ffmpeg.format='MPEG4'
s.render.ffmpeg.codec='H264'
s.render.ffmpeg.constant_rate_factor='HIGH'
s.render.filepath=str(out/'vault-unlock.mp4')
bpy.ops.wm.save_as_mainfile(filepath=str(out/'Fineworth Vault.blend'))
bpy.ops.render.render(animation=True)

