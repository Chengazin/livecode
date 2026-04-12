#!/usr/bin/env python3
import argparse
import json
import os
import sys


def print_json(payload):
    print(json.dumps(payload, ensure_ascii=False), flush=True)


def build_parser():
    parser = argparse.ArgumentParser(description="Offline speech-to-text using faster-whisper")
    parser.add_argument("--input", required=True, help="Path to input audio file")
    parser.add_argument("--model", default="small", help="Model name or local model path")
    parser.add_argument("--language", default="", help="Optional language code, e.g. en or ru")
    parser.add_argument("--device", default="auto", help="cpu, cuda, or auto")
    parser.add_argument("--compute-type", default="int8", help="Compute type, e.g. int8/float16")
    parser.add_argument("--beam-size", type=int, default=1, help="Beam search size")
    parser.add_argument("--vad-filter", action="store_true", help="Enable VAD filter")
    return parser


def main():
    args = build_parser().parse_args()

    input_path = os.path.abspath(args.input)
    if not os.path.isfile(input_path):
        print_json(
            {
                "status": "error",
                "code": "invalid_input",
                "message": "Input audio file was not found.",
            }
        )
        return 2

    try:
        from faster_whisper import WhisperModel
    except Exception:
        print_json(
            {
                "status": "error",
                "code": "dependency_missing",
                "message": "Install dependency: pip install faster-whisper",
            }
        )
        return 3

    try:
        model = WhisperModel(
            args.model,
            device=args.device,
            compute_type=args.compute_type,
        )

        language = args.language.strip().lower() or None
        beam_size = max(1, int(args.beam_size))
        segments, info = model.transcribe(
            input_path,
            language=language,
            beam_size=beam_size,
            vad_filter=bool(args.vad_filter),
            condition_on_previous_text=False,
        )

        chunks = []
        for segment in segments:
            value = str(getattr(segment, "text", "")).strip()
            if value:
                chunks.append(value)

        text = " ".join(chunks).strip()
        if text == "":
            print_json(
                {
                    "status": "error",
                    "code": "no_speech",
                    "message": "No speech recognized.",
                }
            )
            return 4

        print_json(
            {
                "status": "ok",
                "text": text,
                "language": getattr(info, "language", None),
            }
        )
        return 0
    except Exception as exc:
        print_json(
            {
                "status": "error",
                "code": "runtime_failed",
                "message": str(exc),
            }
        )
        return 5


if __name__ == "__main__":
    raise SystemExit(main())
