"""Package the Blender preview. PyAV and Pillow are required."""
from pathlib import Path
import av
from PIL import Image

root = Path(__file__).resolve().parents[2]
sample = root / 'output/vault-preview'
media = root / 'application/public/media/vault'
media.mkdir(parents=True, exist_ok=True)
with av.open(str(sample/'vault-unlock.mp4')) as source, av.open(str(media/'desktop.mp4'), 'w', options={'movflags': '+faststart'}) as dest:
    original = source.streams.video[0]
    stream = dest.add_stream_from_template(original)
    for packet in source.demux(original):
        if packet.dts is not None:
            packet.stream = stream
            dest.mux(packet)
with av.open(str(media/'desktop.mp4')) as source, av.open(str(media/'mobile.mp4'), 'w', options={'movflags': '+faststart'}) as dest:
    stream = dest.add_stream('libx264', rate=24)
    stream.width, stream.height, stream.pix_fmt = 304, 540, 'yuv420p'
    stream.options = {'crf': '18'}
    for index, frame in enumerate(source.decode(video=0)):
        image = frame.to_image()
        progress = min(1, max(0, (index-6)/27))
        ease = progress*progress*(3-2*progress)
        center = round(image.width*(.69-.19*ease))
        crop = image.crop((center-152, 0, center+152, 540))
        for packet in stream.encode(av.VideoFrame.from_image(crop)):
            dest.mux(packet)
    for packet in stream.encode():
        dest.mux(packet)
for orientation in ('desktop','mobile'):
    with av.open(str(media/f'{orientation}.mp4')) as source:
        frames = list(source.decode(video=0))
        assert len(frames) == 60 and source.streams.video[0].average_rate == 24
        poster = frames[0].to_image()
        poster.save(media/f'{orientation}.webp', lossless=True, method=6)
        assert Image.open(media/f'{orientation}.webp').convert('RGB').tobytes() == poster.convert('RGB').tobytes()
        print(orientation, poster.size, len(frames), 'frames, poster matches decoded frame one')
